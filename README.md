# Sistema de Reservas — Evaluación Técnica (puntos c y d)

API REST para la gestión de reservas de un local con 4 ubicaciones (A, B, C, D).
Implementa el **punto c** (solicitud de reserva con asignación automática de
ubicación y mesas) y el **punto d** (listado por fecha en una sola consulta SQL).

**Stack:** Laravel 13 · PHP >= 8.3 · MariaDB 11 · Sanctum (tokens Bearer) · PHPUnit

> **Todo corre con Docker.** No necesitás instalar PHP, Composer ni MySQL.

---

## 1. Cómo correrlo

Requisito único: **Docker** (con Compose).

```bash
docker compose up -d --build
```

El primer arranque tarda un poco (construye la imagen). El contenedor `app`
espera a que la base esté lista, crea tablas, carga datos demo y levanta el
servidor solo.

- API: http://127.0.0.1:8000
- Usuario demo: **`test@example.com` / `password`**
- La base arranca con 28 mesas: A `[2,2,2,4,4,4,6,8]`, B igual que A,
  C `[2,4,4,4,6,8]`, D `[2,2,4,4,6,10]`.

Comandos útiles:

```bash
docker compose logs -f app        # ver qué pasa en el servidor
docker compose stop               # frenar sin borrar nada
docker compose down               # bajar (los datos persisten)
docker compose down -v            # bajar Y borrar la base por completo
```

Si preferís correr fuera de Docker para desarrollar: `composer install`,
configurar `.env` con tu MariaDB local y `php artisan migrate:fresh --seed`.

---

## 2. Cómo probarla (guion de 5 minutos)

Todo con `curl`. Si preferís Postman, los mismos pedidos sirven
(pestaña Auth → Bearer Token + header `Accept: application/json`).

Fechas reales de esta semana para las pruebas: **viernes 2026-08-28**,
**sábado 2026-08-29**, **domingo 2026-08-30**.

### Paso 1 — Login y token

```bash
curl -s -X POST localhost:8000/api/login -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password"}'
```

Copiá el valor de `"token"` de la respuesta. En todos los pedidos siguientes
mandá el header `Authorization: Bearer TU_TOKEN`.

### Paso 2 — Punto c: crear una reserva

12 personas un martes 20:00. Esperado: `201`, `"location": "A"` y dos mesas
(de capacidades 4 y 8 = ajuste exacto).

```bash
curl -s -X POST localhost:8000/api/reservations \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer TU_TOKEN" \
  -d '{"date":"2026-08-25","time":"20:00","people_count":12}'
```

### Paso 3 — Ver la asignación automática en acción

Repetí el mismo pedido dos veces más:

- 1ª reserva → A, mesas 4+8 (ajuste perfecto).
- 2ª reserva → A, mesas 2+4+6 (otro ajuste perfecto, ahora son 3 mesas).
- 3ª reserva → ya no entra en A con ≤ 3 mesas: **cae automáticamente en B**.

Ese salto A → B es la regla del enunciado: se prueba la primera ubicación en
orden y solo si ninguna combinación sirve se pasa a la siguiente.

### Paso 4 — Punto d: listado del día

```bash
curl -s "localhost:8000/api/reservations?date=2026-08-25" \
  -H "Authorization: Bearer TU_TOKEN"
```

Devuelve todas las reservas del día agrupadas por ubicación y ordenadas por
hora. Cada fila incluye cliente, gente, ventana horaria y los números de mesa
unidos (ej. `"mesas": "3,5"`), aunque la reserva use varias mesas.

### Paso 5 — Casos de error

| Caso | Pedido | Esperado |
|---|---|---|
| Fuera de horario | domingo 2026-08-30 `"time": "17:00"` (atiende 12–16) | 422 |
| Excede el cierre | viernes 2026-08-28 `"23:00"` (23:00 + 120 min > medianoche) | 422 |
| Cruza medianoche OK | sábado 2026-08-29 `"23:30"` → termina domingo 01:30 | 201 |
| Grupo imposible | `"people_count": 40` (necesitaría más de 3 mesas) | 409 |
| Sin anticipación | `"time"` = hora actual + 5 minutos | 422 |
| Payload inválido | `"people_count": 0` o fecha mal formada | 422 |
| Sin token | quitá el header Authorization | 401 |

El mensaje JSON de cada error explica la regla violada.

### Ojo con el throttle

Login y registro permiten **6 intentos por minuto** por IP (protección contra
fuerza bruta). Si iterás rápido y te devuelve `429 Too Many Requests`,
esperá un minuto.

---

## 3. Tests automatizados

17 tests corren contra una base dedicada (`reservas_test`, creada
automáticamente): **no tocan los datos demo**.

```bash
docker compose exec app php artisan test
```

Cobertura: asignación por orden de ubicación, unión de mesas con ajuste
perfecto, tope de 3 mesas, fuera de horario, viernes excede cierre, sábado
cruza medianoche, anticipación mínima, solapamientos entre ubicaciones,
auth 401, validación de payload, listado en 1 sola query, orden del listado.

### Prueba manual de concurrencia (opcional)

Tres pedidos simultáneos a la misma franja deben terminar en mesas distintas,
nunca la misma mesa repetida:

```bash
TOKEN="TU_TOKEN"

for i in 1 2 3; do
  curl -s -X POST localhost:8000/api/reservations \
    -H "Accept: application/json" -H "Content-Type: application/json" \
    -H "Authorization: Bearer $TOKEN" \
    -d '{"date":"2026-09-01","time":"20:00","people_count":4}' &
done
wait

docker compose exec app php artisan tinker --execute="App\Models\Reservation::with('tables')->whereDate('starts_at','2026-09-01')->get()->each(fn(\$r) => print('reserva '.\$r->id.': '.\$r->location.' mesas '.\$r->tables->pluck('number')->implode(',').PHP_EOL));"
```

Lo que garantiza el lock pesimista: ningún número de mesa aparece en dos
reservas, aunque los tres pedidos hayan entrado al mismo tiempo.

Para inspeccionar la base directamente:

```bash
docker compose exec db mariadb -ureservas -psecret reservas
```

---

## 4. Cómo funciona por dentro (resumen)

Arquitectura en capas, reglas de negocio configurables, locks transaccionales:

```
routes/api.php
   └─ ReservationController
        ├─ StoreReservationRequest      (valida formato)
        ├─ ReservationService           (orquesta la creación)
        │    ├─ ScheduleValidator       (horarios + anticipación, sin BD)
        │    └─ AvailabilityService     (mesas libres, combinaciones, cache)
        └─ ReservationQueryService      (listado por fecha, punto d)

AuthController                        (register / login / logout con Sanctum)
```

Punto c en una línea: se validan reglas baratas, y en una transacción se
prueban ubicaciones A→B→C→D buscando la mejor combinación de hasta 3 mesas
libres para la ventana horaria; gana la primera ubicación viable, o responde
409 si ninguna puede sentar al grupo.

Punto d en una línea: una única query con JOINs y `GROUP_CONCAT` trae cada
reserva con sus mesas agrupadas por ubicación y hora, filtrando por una
columna generada indexada.
