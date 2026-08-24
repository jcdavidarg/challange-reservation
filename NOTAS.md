# NOTAS del proyecto reservas-api

Notas de trabajo: explicaciones del funcionamiento, guía para levantar y probar,
y estado de la revisión con correcciones aplicadas.
Última actualización: 2026-08-24.

---

## 1. Resumen

API REST para gestión de reservas de un local con 4 ubicaciones (A, B, C, D).
Implementa el **punto c** (crear reserva con asignación automática de ubicación
y mesas) y el **punto d** (listado por fecha en una sola query).

**Stack:** Laravel 13 · PHP >= 8.3 · MySQL/MariaDB · Sanctum (tokens Bearer) · PHPUnit

**Estructura de capas:**

```
routes/api.php
   └─ ReservationController
        ├─ StoreReservationRequest      (valida formato del payload)
        ├─ ReservationService           (orquesta la creación)
        │    ├─ ScheduleValidator       (horarios + anticipación, sin tocar BD)
        │    └─ AvailabilityService     (mesas libres, combinaciones, cache)
        └─ ReservationQueryService      (listado por fecha, punto d)

AuthController                        (register / login / logout con Sanctum)
```

**Reglas de negocio** — todas en `config/reservas.php`, nada hardcodeado:

| Regla | Valor |
|---|---|
| Ubicaciones en orden | A → B → C → D |
| Duración estándar | 120 min |
| Anticipación mínima | 15 min |
| Máx. mesas por reserva | 3 |
| Horario L-V | 10:00 a 24:00 |
| Horario sábado | 22:00 a 02:00 (cruza medianoche) |
| Horario domingo | 12:00 a 16:00 |

Los horarios se expresan en **minutos desde la medianoche del día de apertura**
(L-V `[600..1440]`, sábado `[1320..1560]`, domingo `[720..960]`): comparar enteros
evita los casos borde del cierre que cruza medianoche.

---

## 2. Flujo del punto c — `POST /api/reservations`

Payload: `{ "date": "Y-m-d", "time": "H:i", "people_count": int }` con token Bearer.

```
POST /api/reservations
        │
        ▼
ReservationController (valida formato: fecha Y-m-d, hora H:i, gente >= 1)
        │
        ▼
ScheduleValidator ← reglas SIN tocar la BD (rechazos baratos):
        ├─ ¿el horario cae dentro del horario de atención?
        └─ ¿faltan >= 15 minutos para esa hora?
        │
        ▼
DB::transaction — por ubicación en orden A → B → C → D:
        ├─ SELECT mesas LIBRES con lockForUpdate() (FOR UPDATE),
        │   para que dos pedidos simultáneos no elijan la misma mesa
        ├─ busca la mejor combinación de mesas:
        │     mínima cantidad de mesas → mínimo desperdicio → nunca > 3
        │     (ej.: 12 personas en A = mesas de 4+8, ajuste exacto)
        ├─ ¿encontró? → INSERT reserva + attach mesas → COMMIT → 201
        └─ ¿no encontró? → sigue con la siguiente ubicación
        │
        ▼
Ninguna ubicación sirvió → HTTP 409 "no hay disponibilidad"
```

**Códigos de respuesta:**

| Código | Cuándo |
|---|---|
| 201 | Reserva creada (devuelve ubicación, mesas y ventana) |
| 422 | Regla violada: fuera de horario, anticipación < 15 min, payload inválido |
| 409 | Petición válida pero ninguna ubicación puede sentar al grupo con <= 3 mesas |
| 401 | Sin token |

**Detalles técnicos clave:**

- `starts_at` / `ends_at` son DATETIME absolutos: una reserva de sábado 23:30
  termina domingo 01:30 y el solapamiento es aritmética simple
  (`existe.starts_at < fin AND existe.ends_at > inicio`; extremos que se tocan NO solapan).
- El anti-join con `NOT EXISTS` correlacionado responde "mesas SIN superposición"
  en una sola query apoyada en índices.
- El lock pesimista (`FOR UPDATE`) garantiza exclusión mutua: el segundo pedido
  simultáneo queda bloqueado hasta que el primero confirma, y al re-ejecutar su
  consulta ya ve la mesa ocupada.

---

## 3. Punto d — `GET /api/reservations?date=Y-m-d`

UNA SOLA consulta (`ReservationQueryService::listByDate`):

```sql
SELECT r.id, u.name AS cliente, r.people_count, r.starts_at, r.ends_at,
       t.location,
       COUNT(t.id)                              AS cantidad_mesas,
       GROUP_CONCAT(t.number ORDER BY t.number) AS mesas,
       CAST(SUM(t.capacity) AS UNSIGNED)        AS capacidad_total
FROM reservations r
INNER JOIN users u              ON u.id  = r.user_id
INNER JOIN reservation_table rt ON rt.reservation_id = r.id
INNER JOIN tables t             ON t.id  = rt.table_id
WHERE r.reservation_date = :date
GROUP BY r.id, u.name, r.people_count, r.starts_at, r.ends_at, t.location
ORDER BY t.location ASC, r.starts_at ASC
```

- `GROUP_CONCAT` trae los números de mesa en la misma fila: respuesta al problema N+1.
- Filtra por **`reservation_date`, columna generada** (`= DATE(starts_at)`) e
  indexada. Filtrar con `DATE(starts_at)` envolvería la columna en una función y
  MySQL no podría usar ningún índice (problema de *sargability*).
- Orden: agrupado por ubicación y luego por hora.

---

## 4. El cache negativo (qué es y su limitación)

No es un cache de "qué ubicaciones están disponibles". Es más chico:

- Cuando el algoritmo prueba una ubicación para un grupo y **no encuentra
  combinación**, guarda en memoria una marca: *"para la ubicación A a las 20:00
  ya sé que no hay lugar"* (`AvailabilityService::markNoFit`, clave
  `no_fit:<ubicacion>.<timestamp>`).
- Si otra petición pregunta lo mismo dentro del mismo proceso, se saltea la
  consulta bloqueante porque ya conoce la respuesta negativa.

Propiedades importantes:

1. **Solo cachea respuestas NEGATIVAS**, nunca positivas. Es coherente porque en
   este MVP no hay cancelaciones: las mesas libres solo pueden reducirse, así
   que un "no hay" nunca se vuelve falso. Si se agregan cancelaciones, habría
   que invalidar también en ese flujo.
2. **En la práctica casi no opera**: usa el driver `array` (memoria del proceso
   PHP). Con `php artisan serve` / PHP-FPM cada request arranca con memoria
   vacía, así que las marcas mueren con cada request. Solo viviría bajo Laravel
   Octane, workers o tests secuenciales.
3. **FIX aplicado (2026-08-24)**: la clave ahora incluye `people_count`
   (`no_fit:<ubicacion>.<timestamp>.<people>`). Antes un "30 personas no
   entran en A" salteaba erróneamente A para un pedido de 2 personas.

---

## 5. Guía paso a paso: levantar y probar

### Levantar el servidor

```bash
# Requisitos: PHP >= 8.3 (ext mbstring/xml/curl/mysql), Composer, MariaDB corriendo
cd ~/reservas-api

# 1. Dependencias
composer install

# 2. Crear base y usuario (una sola vez; pide password de root de MariaDB)
sudo mysql -e "CREATE DATABASE IF NOT EXISTS reservas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'reservas'@'localhost' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON reservas.* TO 'reservas'@'localhost'; FLUSH PRIVILEGES;"

# 3. Verificar configuración (.env ya existe, apunta a mysql/reservas/secret)
grep APP_KEY .env    # si está vacío: php artisan key:generate

# 4. Crear tablas + datos demo (28 mesas en A/B/C/D + usuario demo)
php artisan migrate:fresh --seed

# 5. Servidor
php artisan serve    # http://127.0.0.1:8000
```

Usuario demo: `test@example.com` / `password`

### Tests automatizados

```bash
php artisan test     # 17 tests feature
```

**ADVERTENCIA (obsoleta desde 2026-08-24):** `phpunit.xml` ahora apunta a la
base `reservas_test` (misma credencial `reservas`/`secret`): correr tests ya
NO borra los datos de desarrollo. Si la base no existe, crearla con:

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS reservas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON reservas_test.* TO 'reservas'@'localhost'; FLUSH PRIVILEGES;"
```

Cobertura: asignación por orden de ubicación, unión de mesas con ajuste exacto,
tope de 3 mesas, fuera de horario, viernes 23h excede cierre, sábado cruza
medianoche, anticipación de 15 min, solapamientos entre ubicaciones, auth 401,
validación de payload, listado en 1 sola query, orden del listado.

### Manual con Postman (o curl)

1. **Login** → `POST http://127.0.0.1:8000/api/login`, body raw JSON:

   ```json
   {"email": "test@example.com", "password": "password"}
   ```

   Copiar el valor de `"token"` de la respuesta.

2. En las próximas requests agregar header
   `Authorization: Bearer <token>` (Postman: pestaña Auth → Bearer Token)
   y `Accept: application/json`.

3. **Crear reserva** → `POST http://127.0.0.1:8000/api/reservations`:

   ```json
   {"date": "2026-08-25", "time": "20:00", "people_count": 12}
   ```

   Esperado: `201` con `"location": "A"` y mesas `[4, 8]`.
   Repetir para ver cómo cae a B cuando A se llena.

4. **Listar** → `GET http://127.0.0.1:8000/api/reservations?date=2026-08-25`.

5. **Casos de error para probar:**

   | Caso | Payload | Esperado |
   |---|---|---|
   | Fuera de horario | domingo `"17:00"` | 422 |
   | Excede cierre | viernes `"23:00"` (23:00 + 120 min > 24:00) | 422 |
   | Cruza medianoche OK | sábado `"23:30"` | 201, termina 01:30 |
   | Grupo imposible | `"people_count": 40` (> 3 mesas) | 409 |
   | Sin anticipación | hora = ahora + 5 min | 422 |
   | Sin token | quitar header Authorization | 401 |

Alternativa sin Postman: `README.md` tiene los mismos ejemplos con `curl`.

---

## 6. Correcciones de la revisión (checklist)

Revisión del 2026-08-23; **las 6 aplicadas el 2026-08-24**. 17/17 tests OK.

- [x] **Bug cache negativo** (`app/Services/AvailabilityService.php`): la clave
  de `markNoFit()` / `hasKnownNoFit()` incluye ahora `people_count`
  (`no_fit:<ubicacion>.<ts>.<people>`). Call sites actualizados en
  `ReservationService::create`.
- [x] **Throttle en auth**: `throttle:6,1` aplicado a `/api/register` y
  `/api/login` en `routes/api.php`.
- [x] **Base de datos separada para tests**: creada `reservas_test` (GRANT al
  usuario `reservas`) y `DB_DATABASE=reservas_test` en `phpunit.xml`. Los tests
  ya no tocan los datos de desarrollo.
- [x] **Retry ante deadlock**: `ReservationService::create` envuelve
  `DB::transaction` en `retry(3, ..., 100ms)` con cláusula `when` que solo
  reintenta ante deadlocks InnoDB (`SQLSTATE 40001`, `QueryException`); otros
  errores se propagan sin re-ejecutar. El cache negativo sobrevive al rollback
  de un intento y es seguro porque las mesas libres solo pueden reducirse.
- [x] **Unificar validación**: nuevo `app/Http/Requests/ListReservationsRequest.php`
  para el `date` del index; `ReservationController::index` ya no valida inline.
- [x] **Doc menor**: README ahora dice "PHP >= 8.3" (stack y requisitos),
  consistente con `composer.json`.

Nota del fix de retry: dentro de un archivo con namespace, tipar el closure
como `Throwable` a secas resuelve a `App\Services\Throwable` (inexistente) y
explota con TypeError; hay que usar `\Throwable`. Detectado por los tests.

No detectado: problemas graves de seguridad (password con cast `hashed`,
tokens revocados en logout, fillables correctos, errores de auth siempre JSON).

---

## 7. Cómo funciona por dentro (detalle técnico, ex README)

Movido acá cuando el README pasó a ser guía práctica Docker-first
(2026-08-24). Complementa secciones 2-4 con el porqué de cada decisión.

### Modelo de datos

```
users ──< reservations >── reservation_table >── tables
          starts_at          (pivot N:M)           location ENUM(A,B,C,D)
          ends_at                                  number     UNIQUE(location,number)
          people_count                             capacity
          reservation_date ← GENERADA = DATE(starts_at), INDEXADA
```

**¿Por qué `starts_at`/`ends_at` son DATETIME completos y no date+time separados?**
El sábado atiende de 22:00 a 02:00 (cruza medianoche). Con datetimes absolutos,
una reserva de sáb 23:30 termina dom 01:30 y las comparaciones de solapamiento
son aritmética simple, sin casos especiales.

**¿Por qué la columna generada `reservation_date`?**
Filtrar con `WHERE DATE(starts_at) = ?` envuelve la columna en una función y
MySQL **no puede usar ningún índice** (problema de *sargability*). La columna
generada materializa el día y se indexa: mismo resultado, acceso por índice.
Es la razón técnica detrás del pedido de "SQL óptimo" del punto d.

**Índices y para qué sirve cada uno**

| Índice | Lo usa |
|---|---|
| `tables(location, capacity)` | búsqueda de mesas por ubicación ya ordenadas por capacidad |
| `tables UNIQUE(location, number)` | integridad: no repetir número de mesa en la sede |
| `reservations(reservation_date, starts_at)` | filtro del punto d y ventanas por día |
| `reservations(starts_at, ends_at)` | chequeo de solapamiento por rango |
| `reservation_table PK(reservation_id, table_id)` | join del punto d; evita duplicar mesa en una reserva |

**Reglas de negocio en `config/reservas.php`** (nada hardcodeado): orden de
ubicaciones, duración 120 min, anticipación 15 min, máx. 3 mesas y horarios.

Los horarios están expresos en **minutos desde la medianoche del día de
apertura**: L-V `[600..1440]`, sábado `[1320..1560]`, domingo `[720..960]`.
Comparar enteros evita parsear horas "24:00"/"02:00 del otro día": si
`inicio + duración > cierre`, se rechaza. Por eso viernes 23:00 (1380+120=1500 >
1440) es inválido, pero sábado 23:30 (1410+120=1530 ≤ 1560) es válido.

### Porqués del punto c

**¿Por qué `retry()` alrededor de la transacción?**
Dos transacciones concurrentes con `FOR UPDATE` pueden chocar por gap locks de
InnoDB y MySQL mata una con error de deadlock (SQLSTATE 40001). El wrapper
reintenta hasta 3 veces con 100 ms de separación, pero SOLO ante deadlocks;
cualquier otro error se propaga sin re-ejecutar.

**¿Por qué `lockForUpdate()` (lock pesimista)?**
Dos pedidos simultáneos para la misma franja leen el mismo set de mesas libres.
Sin lock, ambos elegirían la mesa 4 y habría overbooking. Con
`SELECT ... FOR UPDATE` dentro de la transacción, el segundo pedido queda
bloqueado hasta que el primero confirma, y al ejecutar su consulta ya ve la
mesa ocupada y sigue con otra. Es el patrón estándar para reservas de recursos
finitos. La alternativa optimista (columna de versión + reintento) complica el
flujo sin beneficio aquí, donde la contención es baja.

**¿Por qué la consulta usa `NOT EXISTS` correlacionado?**
Una mesa está ocupada si existe reserva con
`existe.starts_at < ventana_fin AND existe.ends_at > ventana_inicio`
(condición clásica de solapamiento de intervalos; extremos que se tocan NO
solapan). El anti-join responde "mesas SIN superposición" en una sola query,
apoyada en los índices de la pivot, en lugar de traer todo y filtrar en PHP.

**¿Cómo se eligen las mesas? (`findBestTableCombination`)**
Búsqueda exhaustiva por tamaño creciente (1, luego 2, luego 3 mesas) con dos
podas baratas: si incluso sumando las más grandes no cubre las personas, esa
rama muere; si aparece ajuste perfecto (desperdicio 0), corta. Dentro de cada
tamaño gana menor suma de capacidad sobrante. Ejemplo real con el seed:
12 personas en A (capacidades 2,2,2,4,4,4,6,8) → elige **4+8 = 12 exacto**, no
6+8 = 14. Exhaustivo es viable porque C(n,3) con n≤28 mesas son ~3.000
combinaciones triviales; un greedy simple elegiría 2+2+8 y desperdiciaría una
mesa que otro grupo necesita.

**Errores de dominio tipados:** `ReservationException` con factories
(`outsideOpeningHours`, `insufficientLeadTime`, `noAvailability`) y un renderer
global en `bootstrap/app.php` que los convierte en JSON 422/409. Los servicios
lanzan lenguaje del negocio; HTTP se decide en un solo lugar.

### Porqués del punto d

UNA SOLA consulta (`ReservationQueryService::listByDate`): JOINs contra pivot y
mesas + `GROUP_CONCAT(t.number ORDER BY t.number)` para traer los números de
mesa en la MISMA fila que cada respuesta al problema N+1.

```sql
SELECT r.id, u.name AS cliente, r.people_count, r.starts_at, r.ends_at,
       t.location,
       COUNT(t.id)                        AS cantidad_mesas,
       GROUP_CONCAT(t.number ORDER BY t.number) AS mesas,
       CAST(SUM(t.capacity) AS UNSIGNED)  AS capacidad_total
FROM reservations r
INNER JOIN users u              ON u.id  = r.user_id
INNER JOIN reservation_table rt ON rt.reservation_id = r.id
INNER JOIN tables t             ON t.id  = rt.table_id
WHERE r.reservation_date = :date
GROUP BY r.id, u.name, r.people_count, r.starts_at, r.ends_at, t.location
ORDER BY t.location ASC, r.starts_at ASC
```

`EXPLAIN` verificado en MariaDB 11.8 — todos los accesos usan índice:

```
table  type    key
r      ref     reservations_reservation_date_starts_at_index  ← la generada!
u      eq_ref  PRIMARY
rt     ref     PRIMARY (composite)
t      eq_ref  PRIMARY
```

Suposición documentada: el enunciado agrupa "por ubicación y sección"; como
"sección" no está definida en ninguna parte, se interpretó que sección =
ubicación (A-D). El ORDER BY deja el listado agrupado por ese criterio.

---

## 8. Contexto para retomar esta conversación

**Estado al 2026-08-24:**

- Proyecto completo y funcional: puntos c y d implementados con 17 tests.
- Las 6 correcciones de la sección 6 fueron aplicadas y verificadas
  (17/17 tests OK, datos de desarrollo intactos tras la corrida).
- La validación manual con Postman/curl quedó a criterio del dueño; el flujo
  de negocio no cambió, solo se endurecieron auth (throttle) y concurrencia
  (retry ante deadlock).
- Nuevo documento compañero: `NOTAS-2.md` con el mapa paso a paso del request
  del punto c (archivo por archivo, momentos del cache y diagrama Mermaid).
- Dockerización completa (2026-08-24): `docker compose up` levanta app (php:8.4-cli,
  el lock exige PHP >= 8.4) + MariaDB 11 con volumen persistente. Verificado
  end-to-end: punto c (A→A→B), punto d, casos de error, 17/17 tests dentro del
  contenedor sobre `reservas_test`, datos demo intactos tras tests y tras
  restart (seeders idempotentes con firstOrCreate). Dos gotchas resueltos:
  1. **`php artisan serve` filtra el entorno**: solo le pasa al worker una
     whitelist (`ServeCommand::$passthroughVariables`) y espera que configure
     todo leyendo `.env`. Por eso las variables de compose no llegaban al HTTP.
     Fix: el entrypoint escribe las claves `DB_*` directamente en el `.env`
     del contenedor (`docker/app/entrypoint.sh`).
  2. **Precedencia de entorno vs phpunit.xml**: si compose inyectaba
     `DB_DATABASE=reservas` como variable real, los tests la pisaban por más
     `force="true"` en phpunit.xml y borraban la base demo. Fix: compose NO
     inyecta `DB_*`; el `.env` del contenedor es la única fuente de verdad.

**Próximos pasos posibles:** cancelaciones (requerirían invalidar también el
cache negativo en ese flujo), paginación/orden configurable del listado, o CI.

**Para retomar en una sesión futura**, dar este contexto al asistente:

> "Estoy trabajando en ~/reservas-api (Laravel, API de reservas). Leí NOTAS.md.
> Continuamos desde ahí: [aplicar fixes / seguir probando / otra cosa]."
