# Vender Online — Especificación Maestra Core V1

**Estado:** Aprobada  
**Fecha:** 2026-08-19  
**Producto:** vender-online.com.ar  
**Demo oficial:** https://www.vender-online.com.ar/demo  

---

## 1. Objetivo del producto

Vender Online es un producto de software comercializable mediante instalaciones independientes por cliente. Cada comercio paga una implementación inicial y una mensualidad por derecho de uso, mantenimiento, actualizaciones compatibles y soporte definido.

Cada cliente recibe una instalación aislada, con base de datos y configuración propias, pero todas las instalaciones derivan de un único código maestro versionado. Las diferencias entre clientes deben resolverse mediante configuración y módulos, evitando forks del Core.

El Core V1 cubre venta online, operación de pedidos, stock opcional, pagos, ticket no fiscal y delivery propio. No incluye POS/caja, facturación fiscal ARCA, agenda/turnos ni tracking GPS en tiempo real.

---

## 2. Modelo de despliegue

- Una instalación independiente por cliente.
- Una instalación representa un negocio y puede tener una o múltiples sucursales.
- Una sola base de código maestra.
- Branding, módulos y reglas comerciales configurables.
- Hosting tradicional con Apache + PHP + MySQL/MariaDB + HTML/CSS/JS.
- Sin dependencia de SSH, Docker, Redis, workers permanentes, WebSockets o Node.js en producción.
- Se admite una raíz privada separada de `public_html`.

Arquitectura de hosting objetivo:

```text
/home/cliente/
├── api/
│   ├── app/
│   ├── config/
│   ├── database/
│   ├── storage/
│   └── bootstrap/
└── public_html/
    ├── index.php
    ├── api/
    ├── admin/
    ├── operacion/
    ├── delivery/
    ├── mi-cuenta/
    ├── assets/
    └── uploads-public/
```

La lógica privada, secretos, logs, backups, migraciones y archivos privados permanecen fuera de `public_html`.

---

## 3. Interfaces del sistema

### 3.1 Web pública

Debe incluir:

- catálogo;
- categorías;
- buscador;
- ficha de producto/servicio;
- variantes;
- modificadores;
- selección de sucursal cuando corresponda;
- carrito;
- checkout;
- retiro o delivery;
- medios de pago;
- comprobante de transferencia;
- observación general del pedido;
- confirmación;
- seguimiento mediante enlace seguro;
- aprobación/rechazo de modificaciones;
- cancelación cuando la política lo permita;
- PIN de entrega;
- cuenta opcional e historial.

La compra como invitado es el flujo principal.

### 3.2 `/admin/`

Administración completa:

- dashboard;
- pedidos;
- productos;
- servicios;
- categorías;
- sucursales;
- inventario;
- repartidores;
- usuarios;
- roles/permisos;
- promociones;
- cupones;
- pagos;
- horarios;
- ticketera;
- notificaciones;
- configuración;
- reportes;
- auditoría;
- sistema/actualizaciones/backups.

### 3.3 `/operacion/`

Panel simplificado para encargados/vendedores:

- pedidos nuevos;
- aceptar/rechazar;
- proponer cambios;
- iniciar preparación;
- marcar listo;
- asignar/buscar delivery;
- resolver incidencias;
- validar transferencias según permiso;
- imprimir/reimprimir ticket;
- cambiar precio rápidamente;
- disponible/no disponible;
- stock si corresponde.

Debe ser simple, rápido y mobile-first.

### 3.4 `/delivery/`

Mini panel del repartidor:

- teléfono + PIN;
- disponible/no disponible;
- propuestas pendientes;
- aceptar/rechazar;
- entregas activas;
- historial;
- ir a retirar;
- retirado;
- en camino;
- llegué;
- ingresar PIN;
- entregado;
- no pude entregar;
- devolver asignación cuando esté permitido.

---

## 4. Actores y permisos

Actores principales:

1. Propietario / Administrador.
2. Encargado de sucursal.
3. Vendedor / Preparador.
4. Delivery.
5. Cliente.
6. Sistema / Automatizaciones.
7. Operador técnico de Vender Online.

El sistema se basa en:

```text
ROL → PERMISOS → SUCURSALES ASIGNADAS
```

No se deben codificar reglas rígidas basadas exclusivamente en el nombre del rol.

Ejemplos de permisos:

```text
orders.view
orders.accept
orders.reject
orders.modify
orders.prepare
orders.mark_ready
orders.cancel
products.edit_price
products.change_availability
products.manage_stock
deliveries.assign
deliveries.reassign
payments.verify_transfer
settings.manage
users.manage
reports.view
```

---

## 5. Sucursales

Cada negocio puede tener una o más sucursales.

Cada sucursal puede configurar:

- nombre;
- dirección;
- coordenadas;
- teléfono;
- horarios;
- excepciones;
- activa/inactiva;
- catálogo disponible;
- precio override;
- stock;
- modo de inventario;
- radios de delivery;
- tarifas al cliente;
- pago al repartidor;
- pedido mínimo;
- política de delivery cuando no hay repartidores;
- usuarios;
- repartidores;
- ticketera.

### Horarios

Fuera del horario:

- el catálogo sigue visible;
- se puede usar el carrito;
- no se puede confirmar pedido;
- se informa la próxima apertura real.

Ejemplo:

> Cerrado en este momento. Abrimos mañana a las 09:00.

Las excepciones por fecha prevalecen sobre el horario semanal.

---

## 6. Catálogo

El catálogo maestro pertenece al negocio y cada sucursal define su configuración específica.

Tipos:

- producto;
- servicio.

### Producto

Puede:

- tener stock o no;
- admitir retiro;
- admitir delivery;
- tener variantes;
- tener modificadores;
- usar disponibilidad por sucursal.

### Servicio

- se compra/paga online;
- se consume presencialmente;
- no lleva delivery;
- no requiere stock físico;
- no tiene agenda/turnos en V1.

### Carrito mixto

Producto + servicio:

- permitido;
- obliga retiro/presencial;
- misma sucursal;
- sin delivery;
- un solo pedido y pago.

### Variantes

Ejemplos:

- tamaño;
- presentación;
- talle/color.

Cada variante puede tener precio, SKU, stock y disponibilidad propios.

### Modificadores

Pueden ser:

- gratuitos o pagos;
- obligatorios u opcionales;
- selección única o múltiple;
- con mínimo y máximo.

Combos/packs quedan fuera de V1.

---

## 7. Precios y promociones

V1 usa un único precio por producto/variante, con override opcional por sucursal.

No se incluyen listas mayoristas en V1.

### Promociones incluidas

- promociones automáticas básicas;
- descuento por producto;
- descuento por categoría;
- descuento por monto mínimo;
- descuento por medio de pago;
- precio promocional por fechas;
- cupones simples.

Cada promoción/cupón define:

- prioridad;
- acumulable sí/no;
- incompatibilidades;
- vigencia;
- límites de uso.

No se incluyen 2x1, 3x2 ni reglas promocionales complejas.

---

## 8. PricingService — fuente única de verdad

Toda regla económica se calcula en backend.

El mismo motor se usa para:

- carrito/preview;
- checkout;
- creación de pedido;
- modificación de pedido.

JavaScript nunca define el total final.

### Dinero

Todos los montos se calculan y almacenan en centavos enteros.

### Resolución del precio efectivo

```text
variante sucursal
→ variante general
→ producto sucursal
→ producto base
```

### Orden de cálculo

```text
1. Productos / variantes / modificadores
2. Subtotal bruto
3. Promociones automáticas
4. Cupón
5. Descuento por medio de pago
6. Total mercadería
7. Delivery
8. Total final
```

Fórmula:

```text
GROSS_ITEMS
- ITEM_PROMOTIONS
- ORDER_PROMOTIONS
- COUPON_DISCOUNT
- PAYMENT_DISCOUNT
= MERCHANDISE_TOTAL

MERCHANDISE_TOTAL
+ CUSTOMER_DELIVERY_FEE
= GRAND_TOTAL
```

`DRIVER_PAYOUT` se registra por separado.

### Pedido mínimo

Se evalúa sobre productos/servicios antes de descuentos y sin delivery.

Puede existir mínimo distinto para retiro y delivery.

### Cambio de precio

Si el precio cambia entre carrito y confirmación:

- backend recalcula;
- devuelve `PRICE_CHANGED`;
- muestra nuevo preview;
- exige nueva confirmación.

Nunca se confirma silenciosamente un monto diferente al último aceptado por el cliente.

---

## 9. Checkout

Flujo:

```text
CARRITO
→ ENTREGA
→ SUCURSAL / DIRECCIÓN
→ DATOS CLIENTE
→ MEDIO DE PAGO
→ CUPÓN
→ OBSERVACIÓN GENERAL
→ RESUMEN FINAL
→ CONFIRMAR
```

Antes de crear el pedido se valida nuevamente:

- sucursal activa y abierta;
- producto/servicio activo;
- disponibilidad;
- variante;
- modificadores;
- precios;
- stock;
- promociones;
- cupón;
- mínimo;
- cobertura;
- delivery;
- medio de pago;
- total.

El backend es siempre la fuente de verdad.

---

## 10. Clientes

El cliente puede comprar como invitado o con cuenta opcional.

Cuenta opcional:

- direcciones guardadas;
- historial;
- repetir compra;
- datos personales.

La verificación de teléfono es configurable por comercio.

Modos:

- no obligatoria;
- obligatoria;
- condicional según reglas.

El cliente puede agregar una observación general al pedido, pero no observaciones por producto en V1.

---

## 11. Pedido

Un pedido pertenece siempre a una sola sucursal.

Debe conservar snapshots históricos de:

- cliente;
- dirección;
- productos;
- variantes;
- modificadores;
- precios;
- descuentos;
- tarifa delivery;
- pago al repartidor;
- totales.

### Estados de pedido

```text
pending
change_proposed
accepted
in_progress
ready
completed
rejected
cancelled
expired
```

Flujo normal:

```text
pending → accepted → in_progress → ready → completed
```

### Modificaciones propuestas

La sucursal puede proponer cambios antes de aceptar definitivamente.

El cliente recibe comparación original vs propuesta y debe aceptar/rechazar mediante seguimiento seguro notificado por OpenWA.

Nunca se sobrescribe el pedido sin historial.

---

## 12. Preparación

Máquina de estados independiente:

```text
not_started
preparing
ready
handed_over
cancelled
```

Para delivery, preparación y búsqueda de repartidor comienzan en paralelo al aceptar el pedido.

---

## 13. Inventario

Modo configurable por producto/sucursal:

1. Sin control de stock.
2. Stock simple.
3. Stock ilimitado.

Servicios: normalmente sin stock.

### Stock simple

```text
stock_disponible = stock_fisico - stock_reservado
```

Al confirmar checkout:

- se reserva stock;
- si se rechaza/cancela/expira, se libera;
- al aceptar, se consume/compromete.

Las operaciones críticas usan transacciones y locking para evitar sobreventa.

Debe existir historial de movimientos de inventario.

---

## 14. Pagos

Pedido y pago tienen estados independientes.

### Métodos V1

- efectivo;
- transferencia;
- Mercado Pago;
- otros configurables.

### Estados de pago

```text
pending
approved
rejected
cancelled
pending_verification
verified
refund_pending
refund_completed
```

### Efectivo

No bloquea aceptación/preparación una vez operativamente permitido.

### Transferencia

- muestra alias/CBU/CVU;
- comprobante opcional;
- `pending_verification`;
- verificación manual por usuario autorizado.

Por defecto, la preparación espera validación, aunque la política puede configurarse.

### Mercado Pago

Configurable por comercio:

1. Cobro inmediato antes de aceptación.
2. Aceptación antes del pago.

Siempre mediante API/webhook validado. Nunca se confía solo en el retorno del navegador.

### Reembolsos

En V1 son manuales.

El sistema registra `refund_pending` / `refund_completed`, pero la devolución real se realiza fuera del sistema.

---

## 15. Delivery

Cada repartidor puede estar habilitado para:

- una sucursal;
- varias;
- todas las sucursales del negocio.

Disponibilidad efectiva:

```text
activo
+ habilitado para sucursal
+ disponible manualmente
+ capacidad libre
+ restricciones operativas válidas
```

La capacidad simultánea es configurable por repartidor.

### Asignación

Dos modos:

1. Manual.
2. Buscar delivery automáticamente.

Búsqueda automática:

- ofrece a un repartidor por vez;
- timeout configurable;
- rechazo/no respuesta → siguiente;
- aceptación → asignación bloqueada e idempotente.

### Antes de aceptar

El delivery ve:

- sucursal;
- distancia aproximada;
- zona/contexto general;
- remuneración.

No ve la dirección completa del cliente.

### Después de aceptar

Obtiene los datos operativos necesarios, incluida dirección completa.

El cliente ve el nombre del delivery, no su teléfono.

No hay tracking GPS en V1.

### Estados delivery

```text
pending
searching
offered
assigned
accepted
waiting_pickup
picked_up
on_route
arrived
delivered
failed
reassignment_required
cancelled
```

### Flujo normal

```text
pending
→ searching
→ offered
→ accepted
→ waiting_pickup
→ picked_up
→ on_route
→ arrived
→ delivered
```

### Reasignación

Antes de retirar, el repartidor puede devolver una entrega con motivo.

Después de `picked_up`, requiere intervención del encargado/admin.

### Entrega fallida

El delivery puede marcar `No pude entregar` con motivo obligatorio.

La sucursal puede:

- reintentar con otro repartidor;
- convertir a retiro;
- cancelar.

Un segundo intento puede tener nuevo costo de delivery configurable. La sucursal decide caso por caso si lo paga el cliente o lo absorbe el comercio.

### Cambio de modalidad

Antes de que el repartidor retire:

- delivery → retiro, con aprobación sucursal;
- retiro → delivery, con cobertura y aprobación sucursal.

---

## 16. Economía del delivery

Tarifa al cliente y pago al repartidor son independientes.

Ejemplo:

```text
cliente paga delivery      $3.000
repartidor cobra           $2.500
```

Envío gratis:

```text
cliente paga               $0
repartidor cobra           $2.500
```

El comercio absorbe la diferencia.

### Cobertura

Por distancia desde la sucursal.

V1:

```text
distancia_geografica × factor_correccion = distancia_operativa
```

La distancia real por rutas queda para futuro.

Cada tramo define:

- km mínimo;
- km máximo;
- tarifa cliente;
- pago repartidor;
- activo.

---

## 17. PIN de entrega

La entrega se confirma mediante PIN corto generado automáticamente por pedido/entrega.

El PIN:

- se envía por OpenWA;
- también se muestra en seguimiento seguro;
- se almacena hasheado;
- tiene intentos limitados;
- queda inutilizado tras su uso.

Solo permisos administrativos específicos permiten forzar entrega sin PIN y exigen motivo/auditoría.

---

## 18. OpenWA

OpenWA es canal de notificación, no fuente de verdad.

Usos:

- notificaciones al cliente;
- propuestas a repartidores;
- enlaces seguros;
- PIN;
- aprobaciones requeridas.

Las acciones críticas se ejecutan en el backend mediante enlaces/tokens seguros.

Si OpenWA falla, el sistema sigue operando desde paneles.

---

## 19. Ticketera

Ticket informativo/no fiscal.

Configurable por sucursal:

- desactivado;
- impresión manual;
- impresión automática;
- 58/80 mm;
- copias;
- encabezado/pie.

Al aceptar pedido se genera ticket/comanda.

Impresión automática:

```text
hosting → print job → bridge local → ticketera
```

Fallback: impresión manual del navegador.

Fallar al imprimir nunca cancela el pedido.

---

## 20. Notificaciones

Configurables por comercio y por evento.

Eventos posibles:

- pedido recibido;
- aceptado;
- rechazado;
- preparando;
- listo;
- delivery asignado;
- en camino;
- entregado;
- cancelado;
- pago aprobado/rechazado;
- aprobación de cambios.

Arquitectura orientada a eventos para poder sumar email/SMS/push en el futuro.

---

## 21. Dashboard y reportes

### Dashboard V1

- ventas de hoy;
- pedidos de hoy;
- ticket promedio;
- nuevos;
- preparando;
- listos;
- buscando delivery;
- en camino;
- cancelados;
- rechazados;
- stock bajo.

### Reportes V1

Filtros por:

- fecha;
- sucursal;
- producto;
- categoría;
- medio de pago;
- modalidad;
- estado.

Resultados:

- cantidad de pedidos;
- venta bruta;
- descuentos;
- delivery cobrado;
- remuneración delivery;
- venta neta operativa.

Exportación CSV.

---

## 22. Arquitectura backend

Patrón objetivo:

```text
Route
→ Middleware
→ Controller
→ Service
→ Repository
→ MySQL
```

### Controllers

Responsables solo de:

- recibir request;
- validar forma de entrada;
- invocar servicios;
- devolver respuesta.

### Services principales

- OrderService
- PricingService
- InventoryService
- PaymentService
- DeliveryService
- DriverAssignmentService
- PreparationService
- NotificationService
- ScheduleService
- CustomerService
- PromotionService
- PrintingService
- AuditService

### Repositories

Encapsulan acceso SQL mediante PDO + prepared statements.

---

## 23. API

Separación lógica:

```text
/api/public/*
/api/customer/*
/api/operation/*
/api/admin/*
/api/driver/*
/api/webhooks/*
```

Las cuatro interfaces consumen la misma API y reglas de negocio.

Política same-origin por defecto.

---

## 24. Frontend

Stack:

- PHP para render inicial;
- HTML5;
- CSS;
- JavaScript ES6+ modular;
- Fetch API.

No SPA obligatoria.

Estructura JS conceptual:

```text
assets/js/
├── core/
├── shop/
├── admin/
├── operation/
└── delivery/
```

El frontend no replica reglas económicas críticas.

---

## 25. Seguridad

### Sesiones

- `HttpOnly`;
- `Secure`;
- `SameSite=Lax`;
- regeneración al autenticar;
- expiración;
- revocación.

### Contraseñas/PIN

- `password_hash()` / `password_verify()`;
- PIN delivery hasheado;
- rate limiting y bloqueos temporales.

### CSRF

Obligatorio en acciones autenticadas que modifican estado.

### Autorización

Siempre validar:

```text
permiso + alcance de sucursal
```

### Tokens

- aleatorios criptográficamente;
- hash en DB;
- expiración;
- uso único cuando corresponda;
- revocación.

### SQL

PDO + prepared statements. Allowlist para SQL dinámico no parametrizable.

### XSS

Escape según contexto.

### Uploads

- MIME real;
- tamaño;
- extensión permitida;
- nombre generado;
- privados fuera de web;
- prohibir ejecución.

### HTTPS

Obligatorio en producción.

### Headers

- CSP razonable;
- `X-Content-Type-Options`;
- `Referrer-Policy`;
- `Permissions-Policy`;
- `frame-ancestors`.

### Datos de tarjeta

Vender Online nunca almacena datos completos de tarjetas.

---

## 26. Integraciones y secretos

Secretos de infraestructura en configuración privada fuera de `public_html`.

Credenciales comerciales sensibles pueden residir cifradas en DB.

La API administrativa nunca devuelve secretos completos; solo indica si están configurados y los reemplaza si se envía explícitamente un nuevo valor.

Preferencia de cifrado:

1. Sodium.
2. OpenSSL AEAD como fallback.

---

## 27. Webhooks e idempotencia

Mercado Pago y futuras integraciones deben:

- validar firma/origen cuando corresponda;
- ser idempotentes;
- tolerar duplicados;
- registrar eventos;
- consultar al proveedor para verificar estados críticos.

Acciones sensibles como crear pedido, aceptar delivery o procesar pago deben resistir doble clic/doble request.

---

## 28. Instalación y actualizaciones

### Instalador web

Debe:

- verificar requisitos;
- probar DB;
- pedir configuración inicial;
- ejecutar migraciones;
- crear administrador;
- bloquearse tras finalizar.

### Versionado

SemVer:

- patch: bugs/seguridad;
- minor: mejoras Core;
- major: cambios mayores.

### Migraciones

Versionadas y registradas en `schema_migrations`.

### Actualizador web

Flujo:

```text
subir archivos por FTP
→ detectar versión nueva
→ backup DB
→ maintenance mode si corresponde
→ ejecutar migraciones
→ verificar
→ registrar resultado
```

No requiere CLI.

### Backups

- DB desde administración técnica;
- fuera de `public_html`;
- historial;
- backup previo a migraciones.

---

## 29. Tareas diferidas sin workers

Usar `scheduled_jobs` en MySQL.

Ejemplos:

- expirar pedido;
- timeout de oferta delivery;
- reintentar notificación;
- limpiar token.

Ejecución posible por:

1. actividad normal;
2. polling de operación;
3. cron HTTP opcional.

El Core no depende de un worker permanente.

---

## 30. Auditoría y logs

Auditar como mínimo:

- logins relevantes;
- precios;
- stock;
- pedidos;
- pagos;
- roles/permisos;
- delivery;
- configuración;
- reimpresiones;
- acceso técnico.

Cada request importante puede tener `request_id` para correlación.

Los logs nunca deben contener contraseñas, PIN, tokens completos ni access tokens externos.

---

## 31. Datos vivos vs snapshots

### Datos vivos

- producto actual;
- precio actual;
- disponibilidad;
- stock;
- cliente;
- direcciones guardadas;
- sucursal;
- horario;
- tarifas;
- promociones;
- repartidores;
- configuración.

### Snapshots históricos

- producto vendido;
- variante/modificadores;
- precio;
- descuentos;
- datos cliente del pedido;
- dirección;
- tarifa delivery;
- payout repartidor;
- distancia;
- totales;
- ticket;
- propuesta de modificación;
- notificación enviada.

---

## 32. Entidades conceptuales principales

### Negocio

- Business
- Branch
- BusinessSettings
- BranchSettings
- FeatureModule

### Usuarios

- User
- Role
- Permission
- UserRole
- UserBranch
- RolePermission

### Clientes

- Customer
- CustomerAddress
- PhoneVerification

### Catálogo

- Category
- CatalogItem
- ItemImage
- ItemVariant
- ModifierGroup
- Modifier
- ItemModifierGroup
- BranchItem
- BranchVariant

### Inventario

- Inventory
- InventoryReservation
- InventoryMovement

### Carrito

- Cart
- CartItem
- CartItemModifier

### Pedidos

- Order
- OrderItem
- OrderItemModifier
- OrderAddress
- OrderStatusHistory
- OrderChangeProposal
- OrderChangeProposalItem

### Preparación

- OrderPreparation
- PreparationHistory

### Pagos

- Payment
- PaymentTransaction
- PaymentProof
- PaymentStatusHistory
- Refund

### Promociones

- Promotion
- PromotionRule
- Coupon
- PromotionUsage
- OrderDiscount

### Delivery

- Driver
- DriverBranch
- Delivery
- DeliveryOffer
- DeliveryAttempt
- DeliveryEvent
- DeliveryRate
- DeliveryPIN

### Sistema

- Notification
- NotificationTemplate
- NotificationSetting
- SecureToken
- PrinterConfig
- PrintJob
- PrintAttempt
- StoredFile
- AuditLog
- Integration
- IntegrationSecret
- Module
- BusinessModule
- SchemaMigration
- UpdateHistory
- BackupHistory
- ScheduledJob

---

## 33. Borrado

### Puede borrarse físicamente

- carritos abandonados;
- tokens vencidos;
- cache;
- jobs antiguos.

### Se archiva/desactiva

- producto;
- categoría;
- usuario;
- repartidor;
- sucursal;
- promoción.

### No se borra desde operación normal

- pedido;
- pago;
- movimiento de stock;
- delivery histórico;
- auditoría.

---

## 34. Modelo comercial

### Core

Incluye:

- e-commerce;
- pedidos;
- delivery básico completo;
- stock simple;
- Mercado Pago;
- transferencia;
- OpenWA;
- ticket no fiscal;
- promociones básicas;
- reportes básicos;
- seguridad;
- actualizaciones;
- auditoría;
- backups.

### Implementación inicial

Puede incluir:

- instalación;
- configuración;
- branding;
- carga inicial acordada;
- Mercado Pago;
- OpenWA;
- sucursales;
- ticketera;
- capacitación;
- puesta en producción.

### Abono mensual

Cubre:

- derecho de uso;
- mantenimiento;
- actualizaciones compatibles;
- correcciones;
- soporte definido;
- mejoras generales del Core.

No incluye personalizaciones ilimitadas.

### Propiedad

- el cliente es dueño de sus datos;
- Vender Online conserva la propiedad del Core;
- el cliente adquiere derecho de uso según condiciones comerciales.

### Sucursales

Propuesta comercial inicial:

- 1 sucursal incluida;
- sucursales adicionales como adicional mensual.

---

## 35. Módulos futuros

Fuera de Core V1:

- POS / caja simple;
- ARCA / facturación electrónica;
- agenda / turnos;
- fidelización;
- marketing;
- Delivery Pro con GPS;
- ruteo real por calles;
- reportes Pro;
- listas mayoristas;
- combos/promociones avanzadas;
- integraciones contables;
- marketplaces;
- app móvil nativa.

Las personalizaciones deben convertirse en:

1. funcionalidad configurable del Core; o
2. módulo desacoplado.

Evitar forks por cliente.

---

## 36. Demo y sitio comercial

- Sitio comercial: `www.vender-online.com.ar`
- Demo oficial: `www.vender-online.com.ar/demo`

La demo debe permitir recorrer los perfiles principales:

- Administrador;
- Vendedor/Operación;
- Delivery;
- Cliente.

---

## 37. Criterio de finalización Core V1

La V1 se considera operativamente terminada cuando puede ejecutar de punta a punta:

```text
Cliente compra
→ PricingService valida
→ stock reservado
→ pedido creado
→ pago procesado según método
→ sucursal recibe
→ acepta
→ ticket generado
→ preparación comienza
→ búsqueda delivery en paralelo
→ repartidor acepta
→ preparación lista
→ repartidor retira
→ cliente recibe seguimiento/PIN
→ repartidor llega
→ PIN validado
→ entrega completada
→ stock/pago/delivery/historial quedan consistentes
→ administración puede auditar todo
```

Y el mismo paquete puede instalarse para otro comercio sin cambiar código, solo configuración, branding, sucursales y módulos.

---

## 38. Principios no negociables

1. Un único Core maestro; sin forks por cliente.
2. Backend como fuente de verdad para precios, permisos y estados.
3. Pedido, pago, preparación y delivery son máquinas separadas.
4. Operaciones críticas son transaccionales e idempotentes.
5. Snapshots históricos preservan la verdad comercial de cada operación.
6. Servicios externos no deben convertirse en puntos únicos de falla innecesarios.
7. El producto debe poder instalarse y actualizarse sin SSH.
8. Seguridad, backups, auditoría y migraciones son parte del Core, no módulos opcionales.
9. V1 prioriza operación real por encima de complejidad tecnológica.
10. Toda nueva función debe demostrar que pertenece al Core o a un módulo antes de incorporarse.

---

## 39. Fuera de alcance explícito V1

- POS/caja presencial.
- Facturación fiscal ARCA.
- Agenda y reserva de turnos.
- Tracking GPS en vivo.
- Ruteo real por calles.
- Combos/packs.
- Listas de precios múltiples.
- Mayoristas.
- Fidelización/puntos.
- Marketing automatizado avanzado.
- BI avanzado.
- Apps móviles nativas.
- Arquitectura de microservicios.
- Redis/colas/workers obligatorios.
- WebSockets obligatorios.

---

## 40. Estado de la especificación

Esta especificación consolida las decisiones funcionales, operativas, comerciales y técnicas aprobadas para Vender Online Core V1.

Antes de implementación debe realizarse una revisión final de consistencia y, una vez aprobada esa revisión, producir el plan de implementación por fases.
