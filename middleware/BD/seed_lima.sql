
USE `ticketing_platform`;

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- 1. ROLES (ISA: Admin / Organizador / Asistente)
-- =====================================================================

INSERT INTO `role` (`id_rol`, `nombre`, `descripcion`) VALUES
(1, 'Administrador', 'Acceso total al sistema, gestión de usuarios y configuración'),
(2, 'Organizador', 'Crea y gestiona sus eventos, zonas, ve dashboard de recaudación'),
(3, 'Asistente', 'Compra entradas, recibe tickets, valida ingreso');

-- =====================================================================
-- 2. CATEGORÍAS DE EVENTO
-- =====================================================================

INSERT INTO `category_event` (`id_categoria`, `nombre`, `descripcion`, `estado`) VALUES
(1, 'Concierto', 'Presentaciones musicales en vivo de artistas o bandas', 'activa'),
(2, 'Festival Musical', 'Evento musical con varios artistas, bandas o jornadas', 'activa'),
(3, 'Teatro', 'Obras teatrales, musicales y puestas en escena', 'activa'),
(4, 'Danza', 'Presentaciones de danza contemporánea, folclórica, clásica o urbana', 'activa'),
(5, 'Exposición Cultural', 'Muestras de arte, fotografía, patrimonio o cultura', 'activa'),
(6, 'Feria Cultural', 'Ferias de libro, artesanía, gastronomía o tradición local', 'activa'),
(7, 'Cine y Audiovisual', 'Proyecciones, festivales de cine y conversatorios audiovisuales', 'activa'),
(8, 'Taller Cultural', 'Talleres participativos de arte, música, literatura o patrimonio', 'activa');

-- =====================================================================
-- 3. DISTRITOS DE LIMA (43 distritos - UBIGEO 1501xx)
-- =====================================================================
-- Fuente: INEI - Código UBIGEO oficial

INSERT INTO `district` (`id_distrito`, `nombre`, `codigo_ubigeo`) VALUES
(1, 'Ancón', '150101'),
(2, 'Ate', '150102'),
(3, 'Barranco', '150103'),
(4, 'Breña', '150104'),
(5, 'Carabayllo', '150105'),
(6, 'Chaclacayo', '150106'),
(7, 'Chorrillos', '150107'),
(8, 'Cieneguilla', '150108'),
(9, 'Comas', '150109'),
(10, 'El Agustino', '150110'),
(11, 'Independencia', '150111'),
(12, 'Jesús María', '150112'),
(13, 'La Molina', '150113'),
(14, 'La Victoria', '150114'),
(15, 'Lima', '150115'),           -- Cercado de Lima
(16, 'Lince', '150116'),
(17, 'Los Olivos', '150117'),
(18, 'Lurigancho', '150118'),
(19, 'Lurín', '150119'),
(20, 'Magdalena del Mar', '150120'),
(21, 'Miraflores', '150121'),
(22, 'Pachacámac', '150122'),
(23, 'Pucusana', '150123'),
(24, 'Pueblo Libre', '150124'),
(25, 'Puente Piedra', '150125'),
(26, 'Punta Hermosa', '150126'),
(27, 'Punta Negra', '150127'),
(28, 'Rímac', '150128'),
(29, 'San Bartolo', '150129'),
(30, 'San Borja', '150130'),
(31, 'San Isidro', '150131'),
(32, 'San Juan de Lurigancho', '150132'),
(33, 'San Juan de Miraflores', '150133'),
(34, 'San Luis', '150134'),
(35, 'San Martín de Porres', '150135'),
(36, 'San Miguel', '150136'),
(37, 'Santa Anita', '150137'),
(38, 'Santa María del Mar', '150138'),
(39, 'Santa Rosa', '150139'),
(40, 'Santiago de Surco', '150140'),
(41, 'Surquillo', '150141'),
(42, 'Villa El Salvador', '150142'),
(43, 'Villa María del Triunfo', '150143');

-- =====================================================================
-- 4. USUARIOS BASE (para testing inmediato)
-- Password: "password123" -> hash bcrypt de desarrollo
-- =====================================================================

INSERT INTO `user_account` (`id_usuario`, `id_rol`, `nombres`, `apellidos`, `correo`, `password_hash`, `telefono`, `fecha_registro`, `estado`) VALUES
(1, 1, 'Admin', 'Sistema', 'admin@ticketing.com', '$2y$10$VJGTmEPsWRw0pgtrRu4kgutB7sLaey0yEOkhxWrpvTS75kNBi33Jm', '999888777', NOW(), 'activo'),
(2, 2, 'Juan', 'Pérez Organizador', 'organizador@demo.com', '$2y$10$VJGTmEPsWRw0pgtrRu4kgutB7sLaey0yEOkhxWrpvTS75kNBi33Jm', '987654321', NOW(), 'activo'),
(3, 3, 'María', 'García Asistente', 'asistente@demo.com', '$2y$10$VJGTmEPsWRw0pgtrRu4kgutB7sLaey0yEOkhxWrpvTS75kNBi33Jm', '912345678', NOW(), 'activo');

-- =====================================================================
-- 5. ORGANIZADOR DEMO (vinculado a usuario id=2)
-- =====================================================================

INSERT INTO `organizer_profile` (`id_organizador`, `id_usuario`, `nombre_comercial`, `razon_social`, `ruc`, `telefono_contacto`, `descripcion`, `estado`) VALUES
(1, 2, 'Eventos Perú SAC', 'Eventos Perú Sociedad Anónima Cerrada', '20123456789', '987654321', 'Productora de eventos líder en Lima', 'activo');

-- =====================================================================
-- 6. RECINTOS DEMO (Lima - ubicaciones reales representativas)
-- =====================================================================

INSERT INTO `venue` (`id_recinto`, `id_distrito`, `nombre`, `direccion`, `capacidad_maxima`, `estado`) VALUES
(1, 21, 'Estadio Nacional', 'Av. Paseo de la República 4500, Miraflores', 45000, 'activo'),
(2, 31, 'Jockey Club del Perú', 'Av. Javier Prado Este 4200, San Isidro', 25000, 'activo'),
(3, 21, 'Parque de la Exposición', 'Av. 28 de Julio s/n, Miraflores', 15000, 'activo'),
(4, 13, 'Centro de Convenciones Lima', 'Av. La Molina 1200, La Molina', 8000, 'activo'),
(5, 15, 'Gran Teatro Nacional', 'Av. Javier Prado Este 2225, San Borja', 1500, 'activo'),
(6, 30, 'Auditorio Colegio Médico', 'Av. República de Panamá 3850, San Borja', 800, 'activo'),
(7, 16, 'Teatro Municipal de Lima', 'Jr. Ica 377, Cercado de Lima', 1200, 'activo'),
(8, 36, 'Plaza de Toros de Acho', 'Av. Alfonso Ugarte 1100, Rímac', 12000, 'activo'),
(9, 40, 'Costa 21', 'Av. Alameda 21, Surco', 20000, 'activo'),
(10, 13, 'Parque Bicentenario', 'Av. Alameda del Corregidor, La Molina', 10000, 'activo');

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- FIN seed_lima.sql
-- =====================================================================