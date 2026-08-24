-- Corre como root SOLO en el primer inicio del volumen.
-- La base `reservas` y el usuario `reservas` los crea la propia imagen
-- mariadb via MARIADB_DATABASE / MARIADB_USER. Aca agregamos la base de tests.

CREATE DATABASE IF NOT EXISTS reservas_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON reservas_test.* TO 'reservas'@'%';
FLUSH PRIVILEGES;
