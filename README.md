# NexusAcademic Enterprise (v3.0) 🎓
### Sistema Integral de Gestión Académica & Control de Asistencia con QR Dinámico Criptográfico

Plataforma institucional de alto rendimiento desarrollada en **Node.js (ES Modules)**, con base de datos relacional SQLite embebida de alta velocidad (`node:sqlite`), frontend SPA reactivo con diseño *Glassmorphism*, verificación de presencia física (Geofencing Haversine), motor de calificaciones ponderadas con historial inmutable y control de acceso basado en roles (RBAC).

---

## 🚀 Requisitos del Sistema
* **Node.js:** Versión `>= 22.5.0` (Probado y compatible con Node.js v26.x). *Requerido por el módulo nativo `node:sqlite`*.
* **npm:** Versión `>= 10.x` o `11.x`.
* **Git:** Para control de versiones.

---

## 🛠️ Instalación y Puesta en Marcha

1. **Instalar dependencias:**
   ```bash
   npm install
   ```

2. **Configurar variables de entorno:**
   Copia el archivo de plantilla a `.env` (ya preconfigurado para desarrollo local):
   ```bash
   cp .env.example .env
   ```

3. **Poblar la base de datos con datos de prueba:**
   ```bash
   npm run seed
   ```

4. **Iniciar en modo desarrollo (recarga automática en caliente):**
   ```bash
   npm run dev
   ```
   *Acceso directo:* [http://localhost:3000](http://localhost:3000)

5. **Iniciar en modo producción:**
   ```bash
   npm start
   ```

---

## 🧪 Pruebas Automatizadas (Test Suite)

El proyecto cuenta con 34 pruebas automatizadas que validan autenticación, anti-repetición de tokens QR, cálculo de promedios ponderados, geofencing, prevención de IDOR y escalamiento vertical.

```bash
npm test
```

---

## 🔑 Cuentas Preconfiguradas (Datos de Prueba)

| Rol | Correo Electrónico | Contraseña | Capacidades |
| :--- | :--- | :--- | :--- |
| **Administrador** | `admin@nexus.edu` | `Admin123!` | Gestión global, períodos, asignaturas, auditoría global |
| **Docente 1** | `prof.carlos@nexus.edu` | `Teacher123!` | Proyección de QR, pase de lista en vivo, calificaciones |
| **Docente 2** | `prof.elena@nexus.edu` | `Teacher123!` | Gestión de sus propios cursos y evaluaciones |
| **Estudiante 1** | `estudiante.valeria@nexus.edu` | `Student123!` | Escaneo de QR, consulta de notas y asistencia |
| **Estudiante 2** | `estudiante.mateo@nexus.edu` | `Student123!` | Escaneo de QR, consulta de notas y asistencia |

---

## 📱 Pruebas de Escaneo QR desde Teléfonos Móviles

Las APIs del navegador para la cámara (`getUserMedia`) y GPS (`geolocation`) exigen un contexto seguro (**HTTPS** o `localhost`). Para probar el escaneo con smartphones reales en la misma aula o por internet:

```bash
# Opción con localtunnel
npx localtunnel --port 3000

# Opción con ngrok
ngrok http 3000
```
Abre la URL segura (`https://...`) generada en tu teléfono móvil para iniciar sesión como estudiante y escanear el QR proyectado por el docente.

---

## 📁 Estructura del Proyecto

```text
PROTOTIPO_3/
├── data/                  # Base de datos SQLite (nexus_academic.sqlite)
├── docs/                  # Especificación, Arquitectura y Threat Model
├── public/                # Frontend SPA (Vanilla JS + CSS Glassmorphism)
│   ├── css/style.css      # Estilos visuales de vanguardia
│   ├── js/app.js          # Lógica cliente y sintetizador de audio
│   └── index.html         # Interfaz de usuario interactiva
├── src/
│   ├── controllers/       # Controladores REST por dominio
│   ├── database/          # Conexión db.js, schema.sql y seed.js
│   ├── middleware/        # Autenticación JWT y RBAC
│   ├── routes/            # Definición de endpoints API
│   ├── services/          # Lógica de calificaciones y asistencia
│   ├── utils/             # Criptografía HMAC-SHA256, Scrypt y Haversine
│   └── server.js          # Servidor Express y middlewares de seguridad
└── tests/                 # Suites de pruebas con Node.js test runner
    ├── runner.js          # Ejecutor secuencial anti-bloqueo SQLite
    └── *.test.js          # Pruebas de seguridad, negocio y UI
```
