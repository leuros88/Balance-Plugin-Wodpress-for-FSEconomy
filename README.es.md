<p align="center">
  <img src="assets/logobalance.jpeg" alt="Logotipo de FSE Balance" width="200">
</p>

<h1 align="center">FSE Balance — Plugin de WordPress para FSEconomy</h1>

<p align="center">
  Muestra el saldo bancario de cualquier cuenta o grupo de FSEconomy con un shortcode.<br>
  Sincronizado automáticamente cada 30 minutos y servido desde caché — sin llamadas a la API por visita.
</p>

<p align="center">
  <a href="README.md">English</a> ·
  <a href="README.es.md"><strong>Español</strong></a> ·
  <a href="README.pt.md">Português</a>
</p>

<p align="center">
  <a href="https://github.com/leuros88/Balance-Plugin-Wodpress-for-FSEconomy/releases/latest"><img src="https://img.shields.io/github/v/release/leuros88/Balance-Plugin-Wodpress-for-FSEconomy?label=%C3%BAltima%20versi%C3%B3n" alt="Última versión"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/leuros88/Balance-Plugin-Wodpress-for-FSEconomy" alt="Licencia: MIT"></a>
  <img src="https://img.shields.io/badge/WordPress-6.0%2B-blue" alt="WordPress 6.0+">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4" alt="PHP 7.4+">
</p>

---

## Índice

- [Características](#características)
- [Cómo funciona](#cómo-funciona)
- [Requisitos](#requisitos)
- [Instalación](#instalación)
- [Uso](#uso)
- [Actualizaciones](#actualizaciones)
- [Desinstalación](#desinstalación)
- [Seguridad](#seguridad)
- [Licencia](#licencia)

## Características

- 📊 Shortcode `[fse_balance]` que muestra el saldo en caché como moneda con formato (p. ej. `$12,345.67`).
- ⏱️ Sincronización automática en segundo plano cada 30 minutos mediante un intervalo propio de WP-Cron.
- ⚡ Salida en caché — los visitantes nunca provocan una petición en vivo a la API.
- 🔒 Bloqueo anti-solapamiento para evitar consultas concurrentes (cron + refresco manual).
- 🖥️ Página de administración moderna con logotipo, resumen del saldo, estado de sincronización e indicador de salud.
- ⚙️ Ajustes para la URL de la API, refresco manual con un clic y comprobador de actualizaciones.
- 🔄 Actualizaciones auto-hospedadas desde GitHub Releases (un clic + auto-updates).
- 🧹 Desinstalación limpia: elimina opciones, bloqueos y eventos programados.

## Cómo funciona

1. Pega tu URL de la API de FSEconomy (incluyendo tu clave) en `WP Admin > FSE Balance`.
2. Cada 30 minutos, WP-Cron consulta la API, extrae `Statistic > Bank_balance` del XML y lo guarda en la base de datos.
3. El shortcode `[fse_balance]` muestra el valor en caché donde lo coloques.

> **Nota:** WP-Cron se ejecuta con las visitas al sitio. En sitios con poco tráfico, configura un cron real del sistema que llame a `wp-cron.php` cada 30 minutos.

## Requisitos

| Requisito   | Versión         |
|-------------|-----------------|
| WordPress   | 6.0 o superior  |
| PHP         | 7.4 o superior  |
| FSEconomy   | URL de API válida con clave |

## Instalación

1. Ve a [**Releases**](https://github.com/leuros88/Balance-Plugin-Wodpress-for-FSEconomy/releases) y descarga el último **`fse-balance-plugin.zip`** (no los archivos `Source code`).
2. Instálalo por uno de estos métodos:
   - **Opción A — WP Admin (recomendado):** ve a `Plugins > Añadir nuevo > Subir plugin`, sube el `.zip` y pulsa **Activar**.
   - **Opción B — FTP:** extrae el zip y sube la carpeta `fse-balance-plugin/` a `wp-content/plugins/`, luego actívalo en `Plugins`.
3. Ve a `FSE Balance` en el menú y pega tu URL de la API de FSEconomy en el campo **API URL**, luego pulsa **Save URL**.

## Uso

Añade el shortcode donde quieras (entrada, página, widget, bloque):

```
[fse_balance]
```

Muestra el último saldo en caché, p. ej. `$12,345.67`.

Para sincronizar bajo demanda, usa el botón **Force balance refresh now** en la página de ajustes. Consulta la API al momento y actualiza el valor, la fecha y el estado.

## Actualizaciones

El plugin comprueba este repositorio **cada 12 horas** a través de la API de GitHub Releases.

- Cuando hay una versión nueva, WordPress la ofrece en `Escritorio > Actualizaciones` y en `Plugins`, con actualización en un clic y ventana de cambios.
- Activa la instalación totalmente automática con **Activar actualizaciones automáticas** en la fila del plugin.
- Fuerza una comprobación inmediata desde `FSE Balance > Check for updates now`.

## Desinstalación

Desactivar conserva tus ajustes. Eliminar el plugin lo borra todo: opciones, bloqueos, eventos programados y datos de actualización en caché.

## Seguridad

- **Protección SSRF:** solo se aceptan URLs `http/https` en `*.fseconomy.net` con puertos estándar (80/443); se rechazan credenciales en la URL.
- **Parseo XML seguro:** entidades externas desactivadas (protección XXE) con límite de 500 KB por respuesta.
- **Admin reforzado:** comprobación de capacidades (`manage_options`) y nonces en cada formulario y acción.

## Licencia

Licencia MIT — creado por **[Leuros88](https://github.com/leuros88)**.

Ver [LICENSE](LICENSE) para más detalles.
