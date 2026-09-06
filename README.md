# Vender Online Core V1

Sistema de pedidos multi-sucursal para shared hosting. PHP 8.2 puro (sin Composer), MariaDB, Apache. El código privado vive en `api/`; solo `public_html/` es público.

**Estado:** V1 completo — 12 fases entregadas, 22 capability specs, ~163 tests.

## Comandos de desarrollo

```powershell
D:\xampp\php\php.exe tools/run-tests.php                 # suite completa (usar --filter para subconjuntos)
D:\xampp\php\php.exe tools/run-migrations.php            # chequeo de migraciones
```

La suite usa el harness PHP puro del repo y requiere MariaDB arriba (`vo_test` en `127.0.0.1:3306`, root sin password). Los tests crean bases scratch `vo_<fase>_test_<rand>` y fallan ruidosamente si la DB está caída.

---

## Instalación en producción (10 minutos)

1. **Copiar el proyecto** al servidor. La raíz web de Apache debe apuntar a `public_html/` (nunca a la raíz del repo).
2. **Crear una base vacía** en MariaDB (ej: `vo_prod`) + usuario con permisos sobre ella. No ejecutar SQL manual: las migraciones crean todo.
3. **Permisos de escritura** para PHP en `api/storage/` (comprobantes y logs, fuera del alcance web).
4. **Entrar a `https://tudominio/install/`** — asistente de 5 pasos fail-closed:
   - Conexión a la DB (se prueba en vivo)
   - Datos del negocio (nombre, slug, zona horaria `America/Buenos_Aires`)
   - Cuenta del dueño (tiene todos los permisos)
   - Sucursal principal
   - Confirmar → crea las tablas (migraciones 001-010), siembra permisos RBAC, escribe el lock

   El lock impide re-ejecutar el instalador. La configuración generada se escribe FUERA de `public_html/` por seguridad.

---

## Configuración (panel `/admin`, logueado como dueño)

Todo es opt-in: nada sale vivo sin activarlo.

### `/admin/configuracion`

| Sección | Qué configurar |
|---|---|
| **Mercado Pago** | `mp_access_token` de producción, `mp_webhook_secret`, activar MP. Los secretos son write-only: guardarlos y nunca se vuelven a mostrar |
| **Transferencias** | Instrucciones que verá el cliente (CBU/alias) |
| **Notificaciones** | Flag maestro + canal (email / WhatsApp), SMTP (host, puerto, usuario, contraseña write-only, remitente), URL + token del bridge de WhatsApp |

**Webhook de Mercado Pago**: en el dashboard de MP configurar `https://tudominio/api/webhooks/mercadopago` (requiere HTTPS público).

### `/admin/delivery` (si hay envíos)

- **Zonas por sucursal**: nombre + términos de match separados por coma (ej: `centro, caba, microcentro`) + tarifa cliente + remuneración repartidor. El sistema matchea la dirección del cliente contra esos términos; primera zona activa gana.
- **Repartidores**: alta con teléfono y sucursales asignadas.

---

## Manejo diario

### Catálogo (`/admin/catalogo`)

Categorías → productos (variantes opcionales) → modificadores (extras con precio) → precio y disponibilidad por sucursal. Stock por modo: `simple` (cantidad finita) o `unlimited`.

### Flujo de un pedido

```text
Cliente navega → carrito (valida cobertura si es envío) → confirma
→ pedido B-000001 con snapshot inmutable de precios
→ pago:
   Mercado Pago: cliente paga → webhook automático → el pedido se acepta solo
   Transferencia: cliente sube comprobante → se verifica en /admin/pagos
   Efectivo: se acepta manual desde el tablero
→ /admin/operacion (tablero): Aceptar → Preparar → Listo → Completar
   Envío: asignar repartidor → retira → cliente da PIN de 6 dígitos → entregado
→ el stock se consume al aceptar; se libera si se cancela (motivo obligatorio)
```

El total del pedido se fuerza desde el servidor: si el precio cambió entre el preview y la confirmación, el cliente ve `PRICE_CHANGED` y debe re-aceptar.

### Cancelaciones

Desde el tablero o el detalle del pedido, con motivo obligatorio (queda auditado). Pago pendiente → se cancela solo. Pago MP aprobado → queda marcado "requiere reembolso" (reembolso manual en MP).

### Reportes (`/admin/reportes`)

Filtros: rango de fechas (presets hoy/7d/30d), sucursal, producto, categoría, medio de pago, modalidad, estado. Métricas: pedidos, venta bruta, descuentos, delivery cobrado, remuneración repartidor, **venta neta** (= total cobrado − remuneración). Desglose diario + export CSV (UTF-8, separador `;`, abre en Excel).

El dashboard (`/admin`) muestra 11 métricas del día: ventas, pedidos, ticket promedio, pedidos por estado, entregas pendientes/en camino, stock bajo.

### Notificaciones (`/admin/notificaciones`)

Outbox transaccional: cada evento del negocio encola su notificación en la misma transacción del pedido. Envío con hasta 3 reintentos; un fallo de SMTP/WhatsApp **nunca** afecta al pedido. El PIN de entrega viaja por WhatsApp al asignar el repartidor y se recalcula al enviar (nunca se almacena en claro). Log de solo lectura con filtros por estado/canal.

### Auditoría

Toda acción sensible (verificar pagos, transicionar pedidos, cambiar configuración, denegos de permisos) queda en `audit_log` con usuario y request-id.

---

## Mantenimiento

- **Backup**: `mysqldump vo_prod > backup.sql` + copia de `api/storage/` (comprobantes). La DB es lo único con estado crítico.
- **Notificaciones fallidas**: se revisan en el log; cada carga del panel dispara el envío de pendientes (no requiere cron).
- **Stock**: reposición manual desde el catálogo (por diseño).
- **Servicios**: en Windows, levantar MariaDB con `mysqld.exe --defaults-file=...` desacoplado de la sesión, o instalarlo como servicio.

## Estructura

```text
public_html/          # único directorio público (tienda, /admin, /install, /api)
api/
  app/                # Controladores, servicios y repositorios por módulo
    Cart/ Catalog/ Delivery/ Inventory/ Notifications/ Orders/
    Payments/ Reports/ Settings/ Admin/ Auth/ Audit/ Pricing/ Domain/
  database/migrations/  # 001-010 (versionadas, idempotentes)
  storage/              # comprobantes (privado) y logs
  bootstrap/            # autoload + DI
tests/                 # ~163 tests (harness PHP puro, bases scratch reales)
tools/                 # run-tests.php, run-migrations.php
openspec/              # 22 capability specs + 12 cambios archivados con verificación
```
