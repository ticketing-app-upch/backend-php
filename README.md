# Ticketing Backend API (PHP)
> Componente transaccional del sistema de gestión de entradas y control de aforos.

![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=for-the-badge&logo=mysql&logoColor=white)
![JWT](https://img.shields.io/badge/JWT-black?style=for-the-badge&logo=JSON%20web%20tokens)

## 📌 Responsabilidades del Servicio
* Autenticación y autorización basada en Stateless JWT y contraseñas seguras con bcrypt.
* CRUD y configuración de eventos y zonas con límite de aforo.
* Gestión de compras concurrentes mediante transacciones ACID y bloqueo pesimista (`SELECT ... FOR UPDATE`) en MySQL.
* Integración con el microservicio de tarificación dinámica.

## 👥 Desarrolladores Responsables
* **Developer Backend 1:** Yosselin Cosme
* **Developer Backend 2:** Camilo Sebastian Silva Cuzqui

## 🛠️ Requisitos Previos
* PHP 8.1 o superior
* Composer
* Servidor MySQL 8.0+

## 🚀 Puesta en Marcha Local

1. **Instalar dependencias:**
   ```bash
   composer install