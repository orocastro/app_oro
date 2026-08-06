-- ============================================================
-- SETUP COMPLETO PARA XAMPP - Trazabilidad de Joyería
-- Ejecutar esto en phpMyAdmin: http://localhost/phpmyadmin
-- Paso 1: Crear base de datos (o usar la pestaña SQL)
-- ============================================================

-- Crear base de datos si no existe
CREATE DATABASE IF NOT EXISTS trazabilidad CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE trazabilidad;

-- ============================================================
-- TABLA: Usuarios
-- ============================================================
CREATE TABLE IF NOT EXISTS Usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL,
    usuario VARCHAR(255) NOT NULL UNIQUE,
    clave VARCHAR(255) NOT NULL,
    rol VARCHAR(20) NOT NULL DEFAULT 'operador',
    reset_token VARCHAR(255) NULL,
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar usuario admin por defecto (clave: admin123)
-- La clave está hasheada con password_hash
INSERT IGNORE INTO Usuarios (id, nombre, usuario, clave, rol) VALUES
(1, 'Administrador', 'admin', '$2y$10$7q3g5MEU.z2sND6JDct5OOxZaBh5Hwlf1uoJFwuyXLpAKCHXOYTba', 'admin');

-- ============================================================
-- TABLA: Responsables (operarios)
-- ============================================================
CREATE TABLE IF NOT EXISTS Responsables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar responsables de ejemplo
INSERT IGNORE INTO Responsables (id, nombre) VALUES
(1, 'Juan Pérez'),
(2, 'María García'),
(3, 'Carlos López'),
(4, 'Ana Martínez'),
(5, 'Kike (Administrador)');

-- ============================================================
-- TABLA: Procesos (solo los de producción, NO los de estado)
-- ============================================================
CREATE TABLE IF NOT EXISTS Procesos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar solo procesos reales de producción
INSERT IGNORE INTO Procesos (id, nombre) VALUES
(1, 'Fundición'),
(2, 'Laminado'),
(3, 'Trefilado'),
(4, 'Soldadura'),
(5, 'Pulido'),
(6, 'Baño de Oro'),
(7, 'Embalaje'),
(8, 'Eslabonado'),
(9, 'Montaje de Piedras'),
(10, 'Control de Calidad');

-- ============================================================
-- TABLA: Productos
-- ============================================================
CREATE TABLE IF NOT EXISTS Productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar productos de ejemplo
INSERT IGNORE INTO Productos (id, nombre) VALUES
(1, 'Cadena de Oro 18k'),
(2, 'Anillo Solitario'),
(3, 'Pulsera Eslabones'),
(4, 'Dije Corazón'),
(5, 'Aretes Perlas'),
(6, 'Collar Gargantilla'),
(7, 'Sortija Compromiso'),
(8, 'Tobillera Oro');

-- ============================================================
-- TABLA: Materiales
-- ============================================================
CREATE TABLE IF NOT EXISTS Materiales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL UNIQUE,
    protegido TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar materiales de ejemplo
INSERT IGNORE INTO Materiales (id, nombre, protegido) VALUES
(1, 'Oro Puro', 1),
(2, 'Piedras Preciosas', 0),
(3, 'Soldadura', 0),
(4, 'Baño de Oro', 0),
(5, 'Baño de Plata', 0),
(6, 'Cadmio', 0),
(7, 'Cobrizo', 0),
(8, 'Pulimento', 0),
(9, 'Mercurio', 0),
(10, 'Piedras Brillantes', 0),
(11, 'Malla', 0),
(12, 'Cuarzo', 0);

-- ============================================================
-- TABLA: Trazabilidad (órdenes principales)
-- ============================================================
CREATE TABLE IF NOT EXISTS Trazabilidad (
    consecutivo INT AUTO_INCREMENT PRIMARY KEY,
    fecha_entrega DATETIME NOT NULL,
    proceso_id INT NOT NULL,
    responsable_id INT NOT NULL,
    peso_entregado DECIMAL(10, 2) NOT NULL DEFAULT 0,
    peso_ley DECIMAL(10, 2) NOT NULL DEFAULT 0,
    producto_id INT NOT NULL,
    foto_entrega_path TEXT NULL,
    fecha_recibido DATETIME NULL,
    peso_recibido DECIMAL(10, 2) NULL DEFAULT 0,
    foto_recibido_path TEXT NULL,
    merma DECIMAL(10, 2) NOT NULL DEFAULT 0,
    observaciones TEXT NULL,
    estado ENUM('pendiente', 'parcial', 'completado', 'merma_a_favor', 'cancelado') DEFAULT 'pendiente',
    cantidad INT DEFAULT 1,
    creado_por INT DEFAULT 0,
    observaciones_header TEXT NULL,
    FOREIGN KEY (proceso_id) REFERENCES Procesos(id) ON DELETE RESTRICT,
    FOREIGN KEY (responsable_id) REFERENCES Responsables(id) ON DELETE RESTRICT,
    FOREIGN KEY (producto_id) REFERENCES Productos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLA: Movimientos (entregas y devoluciones parciales)
-- ============================================================
CREATE TABLE IF NOT EXISTS Movimientos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consecutivo INT NOT NULL,
    tipo ENUM('entrega', 'recibido') NOT NULL,
    peso DECIMAL(10, 2) NOT NULL DEFAULT 0,
    peso_oro DECIMAL(10, 2) NOT NULL DEFAULT 0,
    peso_materiales DECIMAL(10, 2) NOT NULL DEFAULT 0,
    fecha DATETIME NOT NULL,
    fotos_path TEXT NULL,
    observaciones TEXT NULL,
    registrado_por INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (consecutivo) REFERENCES Trazabilidad(consecutivo) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLA: TrazabilidadMateriales (materiales por movimiento)
-- ============================================================
CREATE TABLE IF NOT EXISTS TrazabilidadMateriales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    consecutivo INT NOT NULL,
    material_id INT NOT NULL,
    movimiento ENUM('entrega', 'recibido') NOT NULL,
    peso DECIMAL(10, 2) NULL DEFAULT 0,
    fotos_path TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (material_id) REFERENCES Materiales(id) ON DELETE RESTRICT,
    FOREIGN KEY (consecutivo) REFERENCES Trazabilidad(consecutivo) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- DATOS DE PRUEBA - Orden ejemplo con movimientos parciales
-- ============================================================

-- Orden: Kike entrega 100gr de Oro a Juan para hacer eslabones
-- Producto: Cadena de Oro 18k (id=1)
-- Proceso: Eslabonado (id=8)
-- Responsable: Juan Pérez (id=1)
INSERT INTO Trazabilidad (
    consecutivo, fecha_entrega, proceso_id, responsable_id, 
    peso_entregado, peso_ley, producto_id, 
    estado, cantidad, creado_por, observaciones_header
) VALUES (
    100, 
    '2025-06-20 08:00:00', 
    8, 
    1, 
    100.00, 
    100.00, 
    1, 
    'parcial', 
    1, 
    1, 
    'Orden de prueba: 100gr de Oro para eslabones'
);

-- Movimiento 1: Entrega inicial (100gr Oro + 30gr Piedras)
INSERT INTO Movimientos (consecutivo, tipo, peso, peso_oro, peso_materiales, fecha, observaciones, registrado_por) VALUES
(100, 'entrega', 130.00, 100.00, 30.00, '2025-06-20 08:00:00', 'Entrega inicial: 100gr Oro + 30gr Piedras', 1);

-- Materiales de la entrega inicial
INSERT INTO TrazabilidadMateriales (consecutivo, material_id, movimiento, peso) VALUES
(100, 1, 'entrega', 100.00),  -- Oro Puro
(100, 2, 'entrega', 30.00);   -- Piedras Preciosas

-- Movimiento 2: Devolución parcial 1 (40gr Oro + 15gr Piedras restantes)
INSERT INTO Movimientos (consecutivo, tipo, peso, peso_oro, peso_materiales, fecha, observaciones, registrado_por) VALUES
(100, 'recibido', 55.00, 40.00, 15.00, '2025-06-22 14:00:00', 'Devolución parcial #1', 1);

INSERT INTO TrazabilidadMateriales (consecutivo, material_id, movimiento, peso) VALUES
(100, 1, 'recibido', 40.00),  -- Oro Puro devuelto
(100, 2, 'recibido', 15.00);   -- Piedras restantes devueltas

-- Movimiento 3: Devolución parcial 2 (60gr Oro + 5gr Piedras)
-- NOTA: Al recibir 60gr + 40gr = 100gr oro, pero hay 30gr de piedras
-- Si recibe 60gr oro + 5gr piedras = 65gr total
-- El oro recibido total sería 100gr (40+60), pero el oro entregado era 100gr
-- Si recibe 100gr oro y 20gr piedras (15+5), total recibido = 120gr
-- Merma = 100 - 100 = 0 (no hay merma en oro)
-- Pero el total es 130 entregado vs 120 recibido = 10gr de merma en materiales
-- Vamos a crear un ejemplo con MERMA A FAVOR:

-- Movimiento 3: Devolución final con Merma a Favor
-- Supongamos que Juan recibe más oro del que entregó (baño de oro agregó peso)
-- Entregó: 100gr oro. Recibe: 105gr oro (baño de oro agregó 5gr)
INSERT INTO Movimientos (consecutivo, tipo, peso, peso_oro, peso_materiales, fecha, observaciones, registrado_por) VALUES
(100, 'recibido', 110.00, 105.00, 5.00, '2025-06-25 16:00:00', 'Devolución final con baño de oro - Merma a Favor de 5gr', 1);

INSERT INTO TrazabilidadMateriales (consecutivo, material_id, movimiento, peso) VALUES
(100, 1, 'recibido', 105.00),  -- Oro Puro recibido (¡más del entregado!)
(100, 2, 'recibido', 5.00);     -- Piedras restantes

-- Actualizar el estado de la orden a "merma_a_favor"
UPDATE Trazabilidad SET 
    fecha_recibido = '2025-06-25 16:00:00',
    peso_recibido = 210.00,  -- Total recibido: 55 + 110 = 165... mejor calcular bien
    estado = 'merma_a_favor',
    merma = -5.00,  -- ¡Negativo! Merma a favor de 5gr
    observaciones = 'Merma a Favor: Se recibieron 5gr más de oro por baño de oro'
WHERE consecutivo = 100;

-- Recalcular correctamente los totales
-- Total entregado oro: 100gr
-- Total recibido oro: 40 + 105 = 145gr
-- ¡Eso no tiene sentido! Mejor un ejemplo más realista:

-- Vamos a borrar y crear uno correcto
DELETE FROM TrazabilidadMateriales WHERE consecutivo = 100;
DELETE FROM Movimientos WHERE consecutivo = 100;
DELETE FROM Trazabilidad WHERE consecutivo = 100;

-- ============================================================
-- EJEMPLO CORRECTO CON MERMA A FAVOR
-- ============================================================
-- Kike entrega 100gr de oro + 30gr de piedras a Juan (total 130gr)
-- Juan trabaja y aplica baño de oro
-- Devolución 1: 40gr oro + 15gr piedras (55gr total)
-- Devolución 2: 65gr oro + 5gr piedras (70gr total)  
-- Total recibido: 105gr oro + 20gr piedras = 125gr
-- Merma en oro: 100 - 105 = -5gr (¡MERMA A FAVOR!)
-- Merma en materiales: 30 - 20 = 10gr (piedras perdidas en el proceso)

INSERT INTO Trazabilidad (
    consecutivo, fecha_entrega, proceso_id, responsable_id, 
    peso_entregado, peso_ley, producto_id, 
    estado, cantidad, creado_por, observaciones_header
) VALUES (
    100, 
    '2025-06-20 08:00:00', 
    8, 
    1, 
    130.00,  -- Total peso entregado
    100.00,  -- Oro puro entregado
    1, 
    'merma_a_favor', 
    1, 
    1, 
    'Ejemplo: 100gr Oro + 30gr Piedras → Merma a Favor de 5gr por baño de oro'
);

-- Movimiento 1: Entrega inicial
INSERT INTO Movimientos (consecutivo, tipo, peso, peso_oro, peso_materiales, fecha, observaciones, registrado_por) VALUES
(100, 'entrega', 130.00, 100.00, 30.00, '2025-06-20 08:00:00', 'Entrega: 100gr Oro puro + 30gr Piedras', 1);

INSERT INTO TrazabilidadMateriales (consecutivo, material_id, movimiento, peso) VALUES
(100, 1, 'entrega', 100.00),  -- Oro Puro
(100, 2, 'entrega', 30.00);   -- Piedras Preciosas

-- Movimiento 2: Devolución parcial 1
INSERT INTO Movimientos (consecutivo, tipo, peso, peso_oro, peso_materiales, fecha, observaciones, registrado_por) VALUES
(100, 'recibido', 55.00, 40.00, 15.00, '2025-06-22 14:00:00', 'Devolución parcial #1: 40gr Oro + 15gr Piedras', 1);

INSERT INTO TrazabilidadMateriales (consecutivo, material_id, movimiento, peso) VALUES
(100, 1, 'recibido', 40.00),
(100, 2, 'recibido', 15.00);

-- Movimiento 3: Devolución final con Merma a Favor
INSERT INTO Movimientos (consecutivo, tipo, peso, peso_oro, peso_materiales, fecha, observaciones, registrado_por) VALUES
(100, 'recibido', 70.00, 65.00, 5.00, '2025-06-25 16:00:00', 'Devolución final: 65gr Oro + 5gr Piedras. Baño de oro agregó 5gr extra', 1);

INSERT INTO TrazabilidadMateriales (consecutivo, material_id, movimiento, peso) VALUES
(100, 1, 'recibido', 65.00),  -- Oro recibido (total 105gr vs 100gr entregados = +5gr)
(100, 2, 'recibido', 5.00);    -- Piedras restantes

-- Actualizar totales de la orden
UPDATE Trazabilidad SET 
    fecha_recibido = '2025-06-25 16:00:00',
    peso_recibido = 125.00,  -- Total recibido: 55 + 70 = 125gr
    merma = -5.00,  -- Merma negativa = Merma a Favor
    observaciones = 'Merma a Favor de 5gr de oro (baño de oro agregó peso). Piedras: 10gr de merma.'
WHERE consecutivo = 100;

-- ============================================================
-- EJEMPLO 2: Orden con merma normal (pérdida)
-- ============================================================
INSERT INTO Trazabilidad (
    consecutivo, fecha_entrega, proceso_id, responsable_id, 
    peso_entregado, peso_ley, producto_id, 
    estado, cantidad, creado_por, observaciones_header
) VALUES (
    101, 
    '2025-06-21 09:00:00', 
    5, 
    2, 
    50.00, 
    50.00, 
    2, 
    'completado', 
    1, 
    1, 
    'Ejemplo: 50gr Oro para pulido → Merma normal de 2gr'
);

INSERT INTO Movimientos (consecutivo, tipo, peso, peso_oro, peso_materiales, fecha, observaciones, registrado_por) VALUES
(101, 'entrega', 50.00, 50.00, 0.00, '2025-06-21 09:00:00', 'Entrega para pulido', 1),
(101, 'recibido', 48.00, 48.00, 0.00, '2025-06-23 15:00:00', 'Devolución con pérdida de 2gr en pulido', 1);

UPDATE Trazabilidad SET 
    fecha_recibido = '2025-06-23 15:00:00',
    peso_recibido = 48.00,
    merma = 2.00,  -- Merma positiva = pérdida de 2gr
    observaciones = 'Merma normal de 2gr en proceso de pulido'
WHERE consecutivo = 101;

-- ============================================================
-- EJEMPLO 3: Orden pendiente (sin devolución aún)
-- ============================================================
INSERT INTO Trazabilidad (
    consecutivo, fecha_entrega, proceso_id, responsable_id, 
    peso_entregado, peso_ley, producto_id, 
    estado, cantidad, creado_por, observaciones_header
) VALUES (
    102, 
    '2025-06-24 10:00:00', 
    4, 
    3, 
    75.00, 
    75.00, 
    3, 
    'pendiente', 
    1, 
    1, 
    'Ejemplo: Orden pendiente sin devolución'
);

INSERT INTO Movimientos (consecutivo, tipo, peso, peso_oro, peso_materiales, fecha, observaciones, registrado_por) VALUES
(102, 'entrega', 75.00, 75.00, 0.00, '2025-06-24 10:00:00', 'Entrega para soldadura', 1);

-- ============================================================
-- ÍNDICES PARA MEJORAR RENDIMIENTO
-- ============================================================
CREATE INDEX IF NOT EXISTS idx_trazabilidad_estado ON Trazabilidad(estado);
CREATE INDEX IF NOT EXISTS idx_trazabilidad_responsable ON Trazabilidad(responsable_id);
CREATE INDEX IF NOT EXISTS idx_trazabilidad_proceso ON Trazabilidad(proceso_id);
CREATE INDEX IF NOT EXISTS idx_trazabilidad_fecha ON Trazabilidad(fecha_entrega);
CREATE INDEX IF NOT EXISTS idx_movimientos_consecutivo ON Movimientos(consecutivo);
CREATE INDEX IF NOT EXISTS idx_movimientos_tipo ON Movimientos(tipo);

-- ============================================================
-- VERIFICACIÓN: Consultar los datos insertados
-- ============================================================
SELECT 'Base de datos lista' AS estado, 
       COUNT(*) AS total_ordenes 
FROM Trazabilidad;
