CREATE DATABASE IF NOT EXISTS ticketing_platform
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;

USE ticketing_platform;

SET FOREIGN_KEY_CHECKS = 0;
DROP VIEW IF EXISTS v_evento_recaudacion;
DROP VIEW IF EXISTS v_evento_ocupacion;
DROP TABLE IF EXISTS ticket_redemption;
DROP TABLE IF EXISTS ticket;
DROP TABLE IF EXISTS payment;
DROP TABLE IF EXISTS order_item;
DROP TABLE IF EXISTS `order`;
DROP TABLE IF EXISTS event_zone;
DROP TABLE IF EXISTS event_catalog;
DROP TABLE IF EXISTS venue;
DROP TABLE IF EXISTS district;
DROP TABLE IF EXISTS category_event;
DROP TABLE IF EXISTS organizer_profile;
DROP TABLE IF EXISTS client_profile;
DROP TABLE IF EXISTS user_account;
DROP TABLE IF EXISTS role;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE role (
  id_rol TINYINT UNSIGNED PRIMARY KEY,
  nombre VARCHAR(30) NOT NULL UNIQUE,
  descripcion VARCHAR(255) NOT NULL
) ENGINE = InnoDB;

CREATE TABLE user_account (
  id_usuario BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_rol TINYINT UNSIGNED NOT NULL,
  nombres VARCHAR(80) NOT NULL,
  apellidos VARCHAR(80) NOT NULL,
  correo VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  telefono VARCHAR(20) NULL,
  marketing_opt_in BOOLEAN NOT NULL DEFAULT FALSE,
  accepted_terms_at DATETIME NULL,
  fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado ENUM('activo', 'bloqueado', 'eliminado') NOT NULL DEFAULT 'activo',
  CONSTRAINT uq_user_email UNIQUE (correo),
  CONSTRAINT fk_user_role FOREIGN KEY (id_rol) REFERENCES role (id_rol),
  INDEX ix_user_role_status (id_rol, estado)
) ENGINE = InnoDB;

CREATE TABLE client_profile (
  id_usuario BIGINT UNSIGNED PRIMARY KEY,
  country_code CHAR(2) NOT NULL,
  city VARCHAR(100) NOT NULL,
  district VARCHAR(100) NULL,
  has_peruvian_nationality BOOLEAN NOT NULL DEFAULT FALSE,
  doc_type ENUM('DNI', 'CE', 'PASAPORTE') NOT NULL,
  doc_number VARCHAR(20) NOT NULL,
  gender ENUM('F', 'M') NOT NULL,
  phone_code VARCHAR(5) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  CONSTRAINT uq_client_document UNIQUE (doc_type, doc_number),
  CONSTRAINT fk_client_user FOREIGN KEY (id_usuario) REFERENCES user_account (id_usuario)
) ENGINE = InnoDB;

CREATE TABLE organizer_profile (
  id_organizador BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario BIGINT UNSIGNED NOT NULL,
  org_type ENUM('PERSONA', 'EMPRESA') NOT NULL DEFAULT 'EMPRESA',
  nombre_comercial VARCHAR(150) NOT NULL,
  razon_social VARCHAR(150) NULL,
  ruc VARCHAR(20) NOT NULL,
  rep_name VARCHAR(160) NULL,
  telefono_contacto VARCHAR(20) NULL,
  country_code CHAR(2) NULL,
  city VARCHAR(100) NULL,
  website VARCHAR(255) NULL,
  descripcion TEXT NULL,
  verification_status ENUM('PENDING', 'VERIFIED', 'REJECTED') NOT NULL DEFAULT 'PENDING',
  estado ENUM('activo', 'suspendido', 'eliminado') NOT NULL DEFAULT 'activo',
  CONSTRAINT uq_organizer_user UNIQUE (id_usuario),
  CONSTRAINT uq_organizer_tax_id UNIQUE (ruc),
  CONSTRAINT fk_organizer_user FOREIGN KEY (id_usuario) REFERENCES user_account (id_usuario)
) ENGINE = InnoDB;

CREATE TABLE category_event (
  id_categoria SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(80) NOT NULL UNIQUE,
  descripcion VARCHAR(255) NULL,
  estado ENUM('activa', 'inactiva') NOT NULL DEFAULT 'activa'
) ENGINE = InnoDB;

CREATE TABLE district (
  id_distrito SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  codigo_ubigeo CHAR(6) NOT NULL UNIQUE
) ENGINE = InnoDB;

CREATE TABLE venue (
  id_recinto BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_distrito SMALLINT UNSIGNED NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  direccion VARCHAR(200) NOT NULL,
  capacidad_maxima INT UNSIGNED NOT NULL,
  estado ENUM('activo', 'inactivo') NOT NULL DEFAULT 'activo',
  CONSTRAINT fk_venue_district FOREIGN KEY (id_distrito) REFERENCES district (id_distrito),
  CONSTRAINT ck_venue_capacity CHECK (capacidad_maxima > 0)
) ENGINE = InnoDB;

CREATE TABLE event_catalog (
  id_evento BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_organizador BIGINT UNSIGNED NOT NULL,
  id_recinto BIGINT UNSIGNED NULL,
  id_categoria SMALLINT UNSIGNED NOT NULL,
  titulo VARCHAR(180) NOT NULL,
  descripcion TEXT NOT NULL,
  fecha_inicio DATETIME NOT NULL,
  fecha_fin DATETIME NULL,
  fecha_publicacion DATETIME NULL,
  imagen_url VARCHAR(500) NULL,
  max_per_order TINYINT UNSIGNED NOT NULL DEFAULT 6,
  estado ENUM('BORRADOR', 'PUBLICADO', 'AGOTADO', 'FINALIZADO', 'ELIMINADO') NOT NULL DEFAULT 'BORRADOR',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_event_organizer FOREIGN KEY (id_organizador) REFERENCES organizer_profile (id_organizador),
  CONSTRAINT fk_event_venue FOREIGN KEY (id_recinto) REFERENCES venue (id_recinto),
  CONSTRAINT fk_event_category FOREIGN KEY (id_categoria) REFERENCES category_event (id_categoria),
  CONSTRAINT ck_event_order_limit CHECK (max_per_order BETWEEN 1 AND 6),
  CONSTRAINT ck_event_dates CHECK (fecha_fin IS NULL OR fecha_fin > fecha_inicio),
  INDEX ix_event_publication (estado, fecha_inicio),
  INDEX ix_event_filter (id_categoria, id_recinto, fecha_inicio)
) ENGINE = InnoDB;

CREATE TABLE event_zone (
  id_zona BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_evento BIGINT UNSIGNED NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  aforo_maximo INT UNSIGNED NOT NULL,
  aforo_disponible INT UNSIGNED NOT NULL,
  precio_base DECIMAL(10,2) NOT NULL,
  estado ENUM('ACTIVA', 'INACTIVA') NOT NULL DEFAULT 'ACTIVA',
  CONSTRAINT uq_event_zone_name UNIQUE (id_evento, nombre),
  CONSTRAINT fk_zone_event FOREIGN KEY (id_evento) REFERENCES event_catalog (id_evento),
  CONSTRAINT ck_zone_capacity CHECK (aforo_maximo > 0),
  CONSTRAINT ck_zone_stock CHECK (aforo_disponible <= aforo_maximo),
  CONSTRAINT ck_zone_price CHECK (precio_base >= 0),
  INDEX ix_zone_event (id_evento, estado)
) ENGINE = InnoDB;

CREATE TABLE `order` (
  id_compra BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario BIGINT UNSIGNED NOT NULL,
  fecha_compra DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  subtotal DECIMAL(10,2) NOT NULL,
  fee DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'PEN',
  estado ENUM('PENDIENTE', 'CONFIRMADA', 'CANCELADA', 'RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
  codigo_operacion VARCHAR(80) NULL,
  idempotency_key VARCHAR(100) NULL,
  CONSTRAINT uq_order_idempotency UNIQUE (id_usuario, idempotency_key),
  CONSTRAINT fk_order_user FOREIGN KEY (id_usuario) REFERENCES user_account (id_usuario),
  CONSTRAINT ck_order_amounts CHECK (subtotal >= 0 AND fee >= 0 AND total = subtotal + fee),
  INDEX ix_order_user_date (id_usuario, fecha_compra),
  INDEX ix_order_status_date (estado, fecha_compra)
) ENGINE = InnoDB;

CREATE TABLE order_item (
  id_detalle_compra BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_compra BIGINT UNSIGNED NOT NULL,
  id_evento BIGINT UNSIGNED NOT NULL,
  id_zona BIGINT UNSIGNED NOT NULL,
  zona_nombre VARCHAR(100) NOT NULL,
  cantidad TINYINT UNSIGNED NOT NULL,
  precio_unitario DECIMAL(10,2) NOT NULL,
  subtotal DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_item_order FOREIGN KEY (id_compra) REFERENCES `order` (id_compra),
  CONSTRAINT fk_item_event FOREIGN KEY (id_evento) REFERENCES event_catalog (id_evento),
  CONSTRAINT fk_item_zone FOREIGN KEY (id_zona) REFERENCES event_zone (id_zona),
  CONSTRAINT ck_item_quantity CHECK (cantidad > 0),
  CONSTRAINT ck_item_price CHECK (precio_unitario >= 0 AND subtotal = cantidad * precio_unitario),
  INDEX ix_item_event_zone (id_evento, id_zona)
) ENGINE = InnoDB;

CREATE TABLE payment (
  id_pago BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_compra BIGINT UNSIGNED NOT NULL,
  metodo_pago ENUM('CARD', 'WALLET') NOT NULL,
  monto DECIMAL(10,2) NOT NULL,
  estado ENUM('PENDING', 'APPROVED', 'DECLINED', 'REFUNDED') NOT NULL,
  codigo_transaccion VARCHAR(80) NULL,
  fecha_pago DATETIME NULL,
  CONSTRAINT uq_payment_transaction UNIQUE (codigo_transaccion),
  CONSTRAINT fk_payment_order FOREIGN KEY (id_compra) REFERENCES `order` (id_compra),
  CONSTRAINT ck_payment_amount CHECK (monto >= 0),
  INDEX ix_payment_order (id_compra, estado)
) ENGINE = InnoDB;

CREATE TABLE ticket (
  id_ticket BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_detalle_compra BIGINT UNSIGNED NOT NULL,
  codigo_validacion VARCHAR(120) NOT NULL,
  precio_pagado DECIMAL(10,2) NOT NULL,
  fecha_emision DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado ENUM('EMITIDO', 'VALIDADO', 'ANULADO') NOT NULL DEFAULT 'EMITIDO',
  CONSTRAINT uq_ticket_code UNIQUE (codigo_validacion),
  CONSTRAINT fk_ticket_item FOREIGN KEY (id_detalle_compra) REFERENCES order_item (id_detalle_compra),
  CONSTRAINT ck_ticket_price CHECK (precio_pagado >= 0),
  INDEX ix_ticket_item (id_detalle_compra, estado)
) ENGINE = InnoDB;

CREATE TABLE ticket_redemption (
  id_redemption BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_ticket BIGINT UNSIGNED NOT NULL,
  validated_by BIGINT UNSIGNED NULL,
  validated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  validation_status ENUM('ACCEPTED', 'ALREADY_USED', 'REJECTED') NOT NULL,
  CONSTRAINT uq_ticket_redemption UNIQUE (id_ticket),
  CONSTRAINT fk_redemption_ticket FOREIGN KEY (id_ticket) REFERENCES ticket (id_ticket),
  CONSTRAINT fk_redemption_user FOREIGN KEY (validated_by) REFERENCES user_account (id_usuario)
) ENGINE = InnoDB;

CREATE OR REPLACE VIEW v_evento_ocupacion AS
SELECT
  e.id_evento,
  e.titulo,
  z.id_zona,
  z.nombre AS zona_nombre,
  z.aforo_maximo,
  z.aforo_maximo - z.aforo_disponible AS vendidos,
  z.aforo_disponible,
  ROUND((z.aforo_maximo - z.aforo_disponible) / z.aforo_maximo, 4) AS ocupacion
FROM event_catalog e
JOIN event_zone z ON z.id_evento = e.id_evento
WHERE z.estado = 'ACTIVA';

CREATE OR REPLACE VIEW v_evento_recaudacion AS
SELECT
  oi.id_evento,
  oi.id_zona,
  oi.zona_nombre,
  SUM(CASE WHEN o.estado = 'CONFIRMADA' THEN oi.cantidad ELSE 0 END) AS tickets_vendidos,
  ROUND(SUM(CASE WHEN o.estado = 'CONFIRMADA' THEN oi.subtotal ELSE 0 END), 2) AS recaudacion
FROM order_item oi
JOIN `order` o ON o.id_compra = oi.id_compra
GROUP BY oi.id_evento, oi.id_zona, oi.zona_nombre;

-- La suma de las zonas y el decremento de stock deben validarse dentro
-- de la transacción de compra del order-service usando SELECT ... FOR UPDATE.
