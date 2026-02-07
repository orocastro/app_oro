-- Archivo: sql/schema.sql
-- Script para la creación de las tablas de la aplicación de Trazabilidad.

-- -----------------------------------------------------
-- 1. Tabla de Usuarios (para el Login)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS Usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    usuario VARCHAR(255) NOT NULL UNIQUE, -- Usado como correo para el login y recuperación
    clave VARCHAR(255) NOT NULL,          -- Almacenará la clave HASHED
    rol VARCHAR(20) NOT NULL DEFAULT 'operador', -- Rol del usuario: 'admin' o 'operador'
    reset_token VARCHAR(255) NULL,        -- Token para recuperación de contraseña
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- 2. Tabla de Responsables (Catálogo)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS Responsables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- 3. Tabla de Procesos (Catálogo)
-- -----------------------------------------------------
-- Se utiliza VARCHAR(255) por si el nombre del proceso es largo.
CREATE TABLE IF NOT EXISTS Procesos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- 4. Tabla de Productos (Catálogo)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS Productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- 4b. Tabla de Materiales (Catálogo)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS Materiales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL UNIQUE,
    protegido TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- 5. Tabla Principal de Trazabilidad (Registro General)
-- -----------------------------------------------------
-- CONSECUTIVO debe ser único y auto-incremental, aunque el usuario lo 'registre'
-- manualmente al recibir, la BD debe garantizar la unicidad y secuencia.
CREATE TABLE IF NOT EXISTS Trazabilidad (
    consecutivo INT AUTO_INCREMENT PRIMARY KEY,
    
    -- DATOS DE ENTREGA (Plantilla 1)
    fecha_entrega DATETIME NOT NULL,
    proceso_id INT NOT NULL,
    responsable_id INT NOT NULL,
    peso_entregado DECIMAL(10, 2) NOT NULL, -- Peso entregado (máximo 2 decimales)
    peso_ley DECIMAL(10, 2) NOT NULL DEFAULT 0,           -- Peso Ley
    producto_id INT NOT NULL,
    foto_entrega_path TEXT NULL,    -- Rutas de las fotos de entrega (JSON array)
    
    -- DATOS DE RECIBIDO (Plantilla 2)
    fecha_recibido DATETIME NULL,
    peso_recibido DECIMAL(10, 2) NULL,      -- Peso recibido (máximo 2 decimales)
    foto_recibido_path TEXT NULL,   -- Rutas de las fotos de recibido (JSON array)
    merma DECIMAL(10, 2) NOT NULL DEFAULT 0,              -- Cálculo automático: peso_entregado - peso_recibido
    observaciones TEXT NULL,
    
    -- Foreign Keys (Relaciones)
    FOREIGN KEY (proceso_id) REFERENCES Procesos(id),
    FOREIGN KEY (responsable_id) REFERENCES Responsables(id),
    FOREIGN KEY (producto_id) REFERENCES Productos(id)
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- 6. Tabla de Materiales por Registro de Trazabilidad
-- -----------------------------------------------------
-- Detalle por material tanto para ENTREGA como RECIBIDO. Almacena pesos y fotos por material.
CREATE TABLE IF NOT EXISTS TrazabilidadMateriales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consecutivo INT NOT NULL,             -- FK lógico al consecutivo de Trazabilidad
    material_id INT NOT NULL,             -- FK a Materiales
    movimiento ENUM('entrega','recibido') NOT NULL,
    peso DECIMAL(10,2) NULL,              -- Puede no aplicar para algunos materiales
    fotos_path TEXT NULL,                  -- JSON array de rutas de fotos por material y movimiento
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (material_id) REFERENCES Materiales(id),
    FOREIGN KEY (consecutivo) REFERENCES Trazabilidad(consecutivo)
) ENGINE=InnoDB;
