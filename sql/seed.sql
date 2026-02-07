-- Archivo: sql/seed.sql
-- Script para poblar las tablas con los datos iniciales (Seed Data).

-- NOTA IMPORTANTE: Las contraseñas en la tabla Usuarios deben ser hasheadas.
-- El script siguiente simula el hashing. En producción, se recomienda usar PASSWORD_HASH() de PHP.

-- -----------------------------------------------------
-- 1. Seed Data para Usuarios
-- Clave: '74348010' -> Simulando hash
-- Clave: '16224901' -> Simulando hash
-- -----------------------------------------------------
INSERT INTO Usuarios (nombre, usuario, clave, rol) VALUES
('ALBEIRO ANDRES CUBIDES GUERRERO', 'andressud@hotmail.com', '$2y$10$wE9m0ZzL9rB7b4D8t5N2Ou.jQyYx7T/QdM2iV5p/x9S0o2Q6lH', 'admin'), -- Hash simulado para 74348010
('VICTOR HUGO BOTERO CUARTAS', 'ganoderico@hotmail.com', '$2y$10$eE5x2wN8qJ0c3P6o9I0v0RkL1sT/yA3oJ5iV7q/h2D8u4G7j9Z', 'operador');   -- Hash simulado para 16224901


-- -----------------------------------------------------
-- 2. Seed Data para Responsables
-- -----------------------------------------------------
INSERT INTO Responsables (nombre) VALUES
('Adriana'), ('Kike'), ('Kate'), ('Mafe'), ('Juan'), ('Julián'), ('Jhonathan'), ('Robinson'), ('Kevin'),
('MANUEL SAMUDIO'), ('FELIPE ARANGO'), ('AS JOYEROS'), ('LUISA MADRID'), ('LIMAYA'), ('SMITH'),
('MARLENY PIETRA'), ('CRISTIAN CORREA'), ('JUAN TAMAYO'), ('CRISTIAN GUALDRON');

-- -----------------------------------------------------
-- 3. Seed Data para Procesos (consolidando duplicados)
-- -----------------------------------------------------
INSERT INTO Procesos (nombre) VALUES
('SALE DEL TALLER'), ('MERMA A FAVOR'), ('INGRESAN PIEDRAS'), ('INGRESA PURO'), 
('ALMA'), ('ARMAR'), ('ARMAR-SOLDAR'), ('BARRIL'), ('BLANQUEAR'), ('BOMBA'),
('BOMBA DDE BRILLO'), ('BOMBA DE BRILLO'), ('CORTAR'), ('CORTE YPULE'), ('DIAMANTAR'),
('ENGASTAR'), ('FELPA'), ('FUNDIR'), ('LAMINAR'), ('LAMINAR Y CORTAR'), ('PURIFICADOR'),
('RECOCER'), ('SOLDAR'), ('SOLDAR CADENA'), ('SOLDAR CUELLO'), ('SOLDAR PALO'),
('SUÑIR'), ('TRIFILAR'), ('TROQUELAR'), ('VACIAR');

-- -----------------------------------------------------
-- 4. Seed Data para Productos
-- -----------------------------------------------------
INSERT INTO Productos (nombre) VALUES
('Van cleef Pulsera'), ('Van cleef Cadena'), ('Van cleef Anillo'), ('Van cleef Herrajes'),
('Van cleef Dijes'), ('Van cleef Topos'), ('Balines N. 3'), ('Balines N. 4'), ('Balines N. 5'),
('Balines'), ('Balines Diamantados'), ('Balines Camándulas'), ('Troquelados Medallas'),
('Troquelados GUCCI'), ('Troquelados Dijes'), ('Troquelados Herrajes'), ('Troquelados Tambores'),
('Gucci Cadenas'), ('Gucci Pulseras'), ('Gucci Anillos'), ('Candongas Lizos'),
('Candongas Entorchados'), ('Candongas Centros de pulsera'), ('Tubo Canutillo');

-- -----------------------------------------------------
-- 5. Seed Data para Materiales
-- -----------------------------------------------------
INSERT INTO Materiales (nombre, protegido) VALUES
('Piedras', 1),
('Soldadura', 0),
('Cierre', 0),
('Hilo', 0),
('Ojos engarse', 0),
('Terminales', 0),
('Conejos', 0),
('Limaya', 0),
('Laminas', 0),
('Van cleef', 0),
('Balin', 0);
