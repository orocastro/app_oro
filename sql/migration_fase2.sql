-- ============================================================
-- Migración Fase 2: Movimientos Parciales
-- Sistema de Trazabilidad de Joyería
-- Compatible con: MySQL 5.7 (Hostinger)
-- ============================================================

-- ============================================================
-- 1. Crear tabla Movimientos (detalle de cada entrega/recibido)
-- ============================================================
-- Esta tabla almacena cada movimiento individual que compone una
-- orden de trazabilidad. Permite registrar múltiples entregas
-- y recibos parciales bajo un mismo consecutivo.
CREATE TABLE IF NOT EXISTS Movimientos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consecutivo INT NOT NULL,
    tipo ENUM('entrega','recibido') NOT NULL,
    peso DECIMAL(10,2) NOT NULL,
    peso_oro DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Peso neto del oro puro en el movimiento',
    peso_materiales DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Peso de otros materiales (plata, piedras, etc.)',
    fecha DATETIME NOT NULL,
    fotos_path TEXT NULL,
    observaciones TEXT NULL,
    registrado_por INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (consecutivo) REFERENCES Trazabilidad(consecutivo)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 2. Alterar tabla Trazabilidad: agregar campos de header
-- ============================================================
-- MySQL 5.7 no soporta IF NOT EXISTS en ALTER TABLE.
-- Usamos procedimientos almacenados con INFORMATION_SCHEMA
-- para verificar existencia de columnas antes de crearlas.

SET @dbname = DATABASE();

-- ----------------------------------------------------
-- 2.1 Agregar columna estado (si no existe)
-- ----------------------------------------------------
-- Controla el ciclo de vida de la orden: pendiente → parcial →
-- completado/merma_a_favor. El estado 'merma_a_favor' indica que
-- hubo una pérdida de peso a favor de la joyería (el trabajador
-- entregó menos material del esperado, generando merma).
SET @sql_estado = NULL;
SELECT CONCAT(
    'ALTER TABLE Trazabilidad ADD COLUMN estado ',
    "ENUM('pendiente','parcial','completado','merma_a_favor','cancelado') NOT NULL DEFAULT 'pendiente'"
) INTO @sql_estado
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Trazabilidad'
  AND COLUMN_NAME = 'estado'
  HAVING COUNT(*) = 0;
SET @sql_estado = IFNULL(@sql_estado, 'SELECT 1');
PREPARE stmt FROM @sql_estado;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Si la columna ya existe pero tiene el ENUM antiguo, actualizarlo
SET @sql_estado_mod = NULL;
SELECT CONCAT(
    'ALTER TABLE Trazabilidad MODIFY COLUMN estado ',
    "ENUM('pendiente','parcial','completado','merma_a_favor','cancelado') NOT NULL DEFAULT 'pendiente'"
) INTO @sql_estado_mod
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Trazabilidad'
  AND COLUMN_NAME = 'estado'
  AND COLUMN_TYPE NOT LIKE "%'merma_a_favor'%";
SET @sql_estado_mod = IFNULL(@sql_estado_mod, 'SELECT 1');
PREPARE stmt FROM @sql_estado_mod;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------
-- 2.2 Agregar columna cantidad (si no existe)
-- ----------------------------------------------------
-- Número de unidades o piezas asociadas a la orden.
SET @sql_cantidad = NULL;
SELECT 'ALTER TABLE Trazabilidad ADD COLUMN cantidad INT NOT NULL DEFAULT 1' INTO @sql_cantidad
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Trazabilidad'
  AND COLUMN_NAME = 'cantidad'
  HAVING COUNT(*) = 0;
SET @sql_cantidad = IFNULL(@sql_cantidad, 'SELECT 1');
PREPARE stmt FROM @sql_cantidad;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------
-- 2.3 Agregar columna creado_por (si no existe)
-- ----------------------------------------------------
-- Usuario que creó la orden de trazabilidad.
SET @sql_creado_por = NULL;
SELECT 'ALTER TABLE Trazabilidad ADD COLUMN creado_por INT NOT NULL DEFAULT 0' INTO @sql_creado_por
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Trazabilidad'
  AND COLUMN_NAME = 'creado_por'
  HAVING COUNT(*) = 0;
SET @sql_creado_por = IFNULL(@sql_creado_por, 'SELECT 1');
PREPARE stmt FROM @sql_creado_por;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------
-- 2.4 Agregar columna observaciones_header (si no existe)
-- ----------------------------------------------------
-- Observaciones a nivel de orden (no del movimiento individual).
SET @sql_obs = NULL;
SELECT 'ALTER TABLE Trazabilidad ADD COLUMN observaciones_header TEXT NULL' INTO @sql_obs
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Trazabilidad'
  AND COLUMN_NAME = 'observaciones_header'
  HAVING COUNT(*) = 0;
SET @sql_obs = IFNULL(@sql_obs, 'SELECT 1');
PREPARE stmt FROM @sql_obs;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------
-- 2.5 Agregar columnas peso_oro y peso_materiales a Movimientos (si no existen)
-- ----------------------------------------------------
-- Estos campos permiten desglosar el peso total del movimiento
-- entre oro puro y otros materiales (plata, piedras, etc.),
-- facilitando el cálculo de mermas y balances.
SET @sql_poro = NULL;
SELECT 'ALTER TABLE Movimientos ADD COLUMN peso_oro DECIMAL(10,2) NOT NULL DEFAULT 0.00' INTO @sql_poro
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Movimientos'
  AND COLUMN_NAME = 'peso_oro'
  HAVING COUNT(*) = 0;
SET @sql_poro = IFNULL(@sql_poro, 'SELECT 1');
PREPARE stmt FROM @sql_poro;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_pmat = NULL;
SELECT 'ALTER TABLE Movimientos ADD COLUMN peso_materiales DECIMAL(10,2) NOT NULL DEFAULT 0.00' INTO @sql_pmat
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Movimientos'
  AND COLUMN_NAME = 'peso_materiales'
  HAVING COUNT(*) = 0;
SET @sql_pmat = IFNULL(@sql_pmat, 'SELECT 1');
PREPARE stmt FROM @sql_pmat;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 3. Índices para performance
-- ============================================================
-- MySQL 5.7 no soporta CREATE INDEX IF NOT EXISTS.
-- Usamos procedimientos almacenados con INFORMATION_SCHEMA.

-- ----------------------------------------------------
-- 3.1 Índices en tabla Movimientos
-- ----------------------------------------------------
SET @sql_idx1 = NULL;
SELECT 'CREATE INDEX idx_movimientos_consecutivo ON Movimientos(consecutivo)' INTO @sql_idx1
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Movimientos'
  AND INDEX_NAME = 'idx_movimientos_consecutivo'
  HAVING COUNT(*) = 0;
SET @sql_idx1 = IFNULL(@sql_idx1, 'SELECT 1');
PREPARE stmt FROM @sql_idx1;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_idx2 = NULL;
SELECT 'CREATE INDEX idx_movimientos_tipo ON Movimientos(tipo)' INTO @sql_idx2
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Movimientos'
  AND INDEX_NAME = 'idx_movimientos_tipo'
  HAVING COUNT(*) = 0;
SET @sql_idx2 = IFNULL(@sql_idx2, 'SELECT 1');
PREPARE stmt FROM @sql_idx2;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_idx3 = NULL;
SELECT 'CREATE INDEX idx_movimientos_peso_oro ON Movimientos(peso_oro)' INTO @sql_idx3
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Movimientos'
  AND INDEX_NAME = 'idx_movimientos_peso_oro'
  HAVING COUNT(*) = 0;
SET @sql_idx3 = IFNULL(@sql_idx3, 'SELECT 1');
PREPARE stmt FROM @sql_idx3;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_idx4 = NULL;
SELECT 'CREATE INDEX idx_movimientos_peso_materiales ON Movimientos(peso_materiales)' INTO @sql_idx4
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Movimientos'
  AND INDEX_NAME = 'idx_movimientos_peso_materiales'
  HAVING COUNT(*) = 0;
SET @sql_idx4 = IFNULL(@sql_idx4, 'SELECT 1');
PREPARE stmt FROM @sql_idx4;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------
-- 3.2 Índices en tabla Trazabilidad
-- ----------------------------------------------------
SET @sql_idx5 = NULL;
SELECT 'CREATE INDEX idx_trazabilidad_estado ON Trazabilidad(estado)' INTO @sql_idx5
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Trazabilidad'
  AND INDEX_NAME = 'idx_trazabilidad_estado'
  HAVING COUNT(*) = 0;
SET @sql_idx5 = IFNULL(@sql_idx5, 'SELECT 1');
PREPARE stmt FROM @sql_idx5;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_idx6 = NULL;
SELECT 'CREATE INDEX idx_trazabilidad_responsable ON Trazabilidad(responsable_id)' INTO @sql_idx6
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Trazabilidad'
  AND INDEX_NAME = 'idx_trazabilidad_responsable'
  HAVING COUNT(*) = 0;
SET @sql_idx6 = IFNULL(@sql_idx6, 'SELECT 1');
PREPARE stmt FROM @sql_idx6;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_idx7 = NULL;
SELECT 'CREATE INDEX idx_trazabilidad_fecha ON Trazabilidad(fecha_creacion)' INTO @sql_idx7
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = @dbname
  AND TABLE_NAME = 'Trazabilidad'
  AND INDEX_NAME = 'idx_trazabilidad_fecha'
  HAVING COUNT(*) = 0;
SET @sql_idx7 = IFNULL(@sql_idx7, 'SELECT 1');
PREPARE stmt FROM @sql_idx7;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
