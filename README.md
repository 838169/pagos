# Microservicio Pagos — Grupo B

Pagos recibe pedidos por RabbitMQ, permite simular su aprobación o rechazo y
publica el resultado para los demás servicios. No realiza cobros bancarios.
Esta copia se trabaja localmente en `C:\xampp\htdocs\grupoB_Pagos`.

## 1. Encender y usar

En XAMPP inicia **Apache** y **MySQL**. Ejecuta cada línea por separado en CMD:

```bat
cd /d C:\xampp\htdocs\grupoB_Pagos
pagos.cmd check-db
pagos.cmd consume
```

Deja el consumidor abierto. Entra a **http://localhost/grupoB_Pagos/**,
selecciona un pedido en **Pagos pendientes** (o en **Historial**) y pulsa **Pagar** o
**Rechazar**. El motivo del rechazo puede modificarse (máximo 150 caracteres).
El historial muestra los últimos 200 pagos; los anteriores se buscan por ID.

```text
PedidoCreado -> guardar PENDIENTE -> Pagar / Rechazar
             -> guardar APROBADO / RECHAZADO -> publicar JSON por RabbitMQ
```

La decisión se guarda antes de conectar con RabbitMQ. Si el envío falla,
queda pendiente de publicación y se reintenta. Los pagos resueltos no se
cambian ni duplican por pulsar nuevamente o recibir el mismo pedido.

**Apagar:** Ctrl+C en la ventana del consumidor. Si CMD pregunta por terminar
el trabajo por lotes, responde S o Y según el idioma. Esto detiene recepción
y reintentos, pero la interfaz aún puede decidir pagos mientras Apache y MySQL
sigan activos. Para apagar la interfaz, Stop en Apache; detén MySQL solo si
ningún otro proyecto lo necesita. No apagues el RabbitMQ compartido.

**Reiniciar:** ejecuta otra vez `pagos.cmd consume`. Reinicia tras cambios en
el código del consumidor o en `.env`. Si termina por pérdida de conexión,
restablece la conexión y vuelve a ejecutarlo: no tiene reinicio automático.

## 2. Configuración e instalación

Requiere PHP 8.1+, PDO MySQL, JSON, mbstring, sockets, OpenSSL, MySQL/MariaDB
con InnoDB y acceso al broker. Compatible con PHP 8.1.25 de este XAMPP.

- `.env`: credenciales y configuración local. Conserva la URL real del equipo.
- `.env.example`: plantilla sin credenciales reales.
- `vendor/`: dependencias de PHP necesarias para RabbitMQ; no se edita ni se sube.
- `composer.phar`: herramienta local de instalación; no forma parte de la lógica.
- `src/`, `bin/`, `public/`, `database/`, `examples/`, `tests/`: estructura del servicio.

Para reinstalar las dependencias:

```bat
"C:\xampp\php\php.exe" composer.phar install
```

También puedes usar `composer install` si lo tienes en PATH. En una instalación
nueva, importa `database/schema.sql` desde phpMyAdmin. No lo vuelvas a importar
sobre una tabla existente. El esquema actual admite PENDIENTE sin migración.
Los registros anteriores conservan sus resultados.

La interfaz permite consultar pedidos y decidir Pagar/Rechazar mediante POST
con CSRF; no incluye inicio de sesión. Se retiró el formulario de prueba web;
WEB_TEST_ENABLED ya no tiene efecto. Tras decidir se muestra el resultado y
se consulta automáticamente el envío pendiente durante aproximadamente un minuto.
El contrato de entrada incluye clienteId, no el nombre del cliente.

El consumidor ya no usa `PAYMENT_REJECT_ORDER_IDS` ni
`PAYMENT_REJECTION_REASON`. `Simulator.php` se conserva para las pruebas
históricas; el flujo actual toma la decisión desde la interfaz.

Si falla `check-db`, revisa MySQL y los valores DB_HOST/DB_PORT de `.env`.
Si RabbitMQ indica servidor de ejemplo, revisa RABBITMQ_URL. Nunca copies
credenciales al README, a capturas de entrega ni a GitHub.

## 3. Contratos que deben acordar los equipos

Los nombres predeterminados son configurables en `.env`. Todos los servicios
involucrados deben usar el mismo broker y vhost.

### Entrada de Pedidos

Exchange `pedidos_exchange`, tipo **fanout**, durable. Cola propia de Pagos:
`pagos_pedidos_queue`, durable. Un fanout entrega a todas sus colas enlazadas
sin seleccionar por routing key.

```json
{
  "evento": "PedidoCreado",
  "pedidoId": 1001,
  "clienteId": 25,
  "fecha": "2026-10-01T10:30:00",
  "total": 5000000,
  "productos": [{"productoId": 15, "cantidad": 2, "precio": 2500000}]
}
```

Pagos utiliza evento, pedidoId, clienteId y total. Los identificadores deben
ser enteros JSON positivos compatibles con INT; el importe debe ser positivo
compatible con DECIMAL(12,2). Los mensajes inválidos se descartan y no producen
PagoRechazado. Los errores técnicos tampoco son rechazos comerciales.

### Salida de Pagos

Exchange `pagos_exchange`, tipo **topic**, durable.

| Decisión | Evento JSON | Routing key |
| --- | --- | --- |
| Pagar | PagoAprobado | pago.aprobado |
| Rechazar | PagoRechazado | pago.rechazado |

```json
{
  "evento": "PagoAprobado",
  "pedidoId": 1001,
  "clienteId": 25,
  "pagoId": 1,
  "total": 5000000,
  "estado": "APROBADO",
  "fecha": "2026-10-12T15:00:00Z"
}
```

```json
{
  "evento": "PagoRechazado",
  "pedidoId": 1002,
  "clienteId": 25,
  "pagoId": 2,
  "total": 5000000,
  "estado": "RECHAZADO",
  "fecha": "2026-10-12T15:01:00Z",
  "motivo": "Rechazado manualmente por el operador"
}
```

Mensajes persistentes, `content_type=application/json` y
`message_id=pagos:<pagoId>`. Se conservan los contratos existentes.

Cada consumidor debe declarar **su propia cola** y enlazarla ANTES de que se
publiquen resultados. Un exchange no conserva mensajes para colas futuras.

| Consumidor | Binding que necesita | Resultado esperado |
| --- | --- | --- |
| Facturación | pago.aprobado | Registrar factura; no facturar rechazos |
| Notificaciones | pago.aprobado y pago.rechazado | Informar ambos resultados |
| Reportes | pago.aprobado y pago.rechazado | Registrar pagos y estadísticas |
| Pedidos | pago.aprobado y pago.rechazado | Actualizar su pedido a PAGADO o CANCELADO |

Los consumidores no deben competir por una misma cola si cada uno necesita
su copia. Sus equipos implementan el procesamiento y el ACK después de guardar.
No cambies nombres o campos unilateralmente (fases 1 y 2 del laboratorio).

La versión local de Pedidos revisada en `Downloads/Modulo pedidos` sí incluye
`src/consumers/pagosConsumer.js`. Usa su cola `pedidos.resultado_pagos` y
transforma APROBADO en PAGADO y RECHAZADO en CANCELADO. Su consumidor debe
estar activo en el mismo broker/vhost. Mientras no haya decisión, Pagos
conserva PENDIENTE y no emite un evento de resultado.

### Conexión de Facturación

```text
Pagos -> pagos_exchange (topic) -> cola_facturacion -> consumidor de Facturación
         routing key: pago.aprobado
```

Pagos publica el JSON en RabbitMQ; no necesita la dirección del computador de
Facturación. Facturación declara su cola durable y la enlaza al exchange
`pagos_exchange` con `pago.aprobado`, en el mismo servidor y vhost de Pagos.

En el código de Facturación revisado, el binding usaba una clave vacía y el
exchange se creaba como fanout si no existía. Su equipo debe cambiar el binding
a `pago.aprobado` y declarar `pagos_exchange` como **topic**, durable. Estas
opciones están en `app/consumer.py`: copiar el `.env` no corrige esos valores.
No se debe borrar el exchange compartido ni cambiar `facturacion_exchange`.

Variables de RabbitMQ que utiliza el `.env` de Facturación:

```dotenv
RABBITMQ_URL=amqps://USUARIO:CONTRASENA@SERVIDOR/VHOST
RABBITMQ_EXCHANGE_PAGOS=pagos_exchange
RABBITMQ_QUEUE=cola_facturacion
RABBITMQ_EXCHANGE_FACTURACION=facturacion_exchange
```

Usar la conexión real del equipo sin publicarla. La configuración de MySQL
corresponde a la base propia de Facturación, no a `db_pagos`. Las instrucciones
completas, el `.env` de ejemplo, los cambios en Python y los comandos están en
[Instrucciones_Facturacion.txt](Instrucciones_Facturacion.txt).

Después del ajuste, reiniciar Facturación, comprobar el binding y su consumidor
activo, y aprobar un **pedido nuevo**. Verificar la factura del mismo `pedidoId`
en Facturación. Los eventos anteriores al binding correcto no se recuperan
automáticamente; recargar un pago ya aprobado no vuelve a publicarlo.
Su script `scripts/publicar_prueba.py` envía directamente a la cola, por lo
que no comprueba el enlace desde Pagos. La integración real sigue pendiente
de esa prueba conjunta; «Publicación confirmada» no demuestra que exista factura.

## 4. Persistencia y recuperación

`pedido_id UNIQUE` evita duplicados. Si una reentrega cambia cliente o total,
se conserva el registro original y se informa conflicto. Las decisiones usan
transacción y bloqueo de fila para impedir resultados contradictorios.

`fecha_procesamiento` contiene recepción mientras está PENDIENTE y se actualiza
a la fecha de decisión al resolverlo. Las fechas se guardan en UTC.

- PENDIENTE: espera decisión humana; no hay resultado que publicar.
- APROBADO/RECHAZADO con `evento_publicado=0`: decisión guardada, envío pendiente.
- `evento_publicado=1`: RabbitMQ confirmó y hubo destino enlazado. **No demuestra
  que un servicio específico haya recibido o procesado el mensaje.**

El consumidor reintenta los envíos según RECOVERY_INTERVAL/RECOVERY_BATCH.
También puedes ejecutar un lote manual:

```bat
pagos.cmd recover
```

Este comando nunca decide los PENDIENTE. Puede haber reentregas si RabbitMQ
recibe el evento y el proceso cae antes de marcarlo en MySQL. Los consumidores
deben ser idempotentes por pagoId/message_id.

## 5. Pruebas ordenadas

### A. Lógica e interfaz sin servicios

```bat
"C:\xampp\php\php.exe" composer.phar test
```

Comprueba JSON, importes, contratos, recuperación, interfaz y aislamiento de
la configuración de las pruebas AMQP. No conecta a RabbitMQ ni a MySQL.

### B. Decisiones con MySQL

Con MySQL encendido, en una CMD de pruebas:

```bat
set "PAGOS_TEST_DSN=mysql:host=127.0.0.1;port=3306;dbname=db_pagos;charset=utf8mb4"
set "PAGOS_TEST_USER=root"
set "PAGOS_TEST_PASSWORD="
"C:\xampp\php\php.exe" tests\manual.php
```

Ajusta usuario y contraseña si corresponde. Usa una tabla TEMPORAL exclusiva
de esa conexión; no modifica pagos reales ni conecta con RabbitMQ.

### C. Transporte RabbitMQ aislado

Para comprobar solo el aislamiento, sin conexión:

```bat
"C:\xampp\php\php.exe" tests\rabbitmq.php --check
```

Para probar el transporte real:

```bat
"C:\xampp\php\php.exe" tests\rabbitmq.php --run
```

Usa el broker configurado, pero declara dos exchanges y cuatro colas con un
prefijo aleatorio `pagos.test.*`. No publica, consume ni elimina recursos
normales de los grupos y no usa SQL. Elimina sus recursos al finalizar; si
pierde conexión durante la limpieza, informa el prefijo exacto pendiente.
Opcionalmente configura PAGOS_TEST_RABBITMQ_URL en esa terminal para otro broker.

Prueba persistencia tras reconectar, reentrega, descarte, JSON aprobado y
rechazado, copias independientes, una cola simulada de Facturación que solo
recibe aprobaciones, falta de destino y recuperación después de restaurar
bindings. **Las colas simuladas no prueban los microservicios reales.**

No ejecutes pruebas con `pagos.cmd publish` sin coordinarlo:
esa herramienta sí publica en el exchange normal y alcanzan a otros grupos.
Los nombres históricos de los archivos de ejemplo no deciden el resultado.

## 6. Pasos pendientes para cumplir Semana 8

Realiza las pruebas conjuntas en este orden, usando pedidos nuevos e IDs
acordados. Conserva capturas/logs con pedidoId y pagoId, sin credenciales.

1. **Acordar contratos y simulación (fases 1, 2 y 6).** Compartir el contrato
   anterior; confirmar que la simulación manual por botones es la esperada.
   El documento no exige explícitamente botones ni aprobación automática.
2. **Preparar consumidores (fases 7–9).** Facturación, Notificaciones y Reportes
   deben tener sus colas enlazadas antes de decidir. Pedidos también si así se
   acordó. Mostrar los bindings en RabbitMQ.
3. **Compra aprobada (fases 6, 7, 11–13).** Crear un pedido con existencias desde
   Pedidos; comprobar PENDIENTE en Pagos; pulsar Pagar. Mostrar el registro
   APROBADO y el mismo pedidoId en la factura, notificación y reporte.
4. **Pago rechazado (fase 13).** Crear otro pedido y pulsar Rechazar con motivo.
   Mostrar el JSON recibido, el registro RECHAZADO, la notificación y el reporte.
   Comprobar que NO se genera factura para ese pedido.
5. **Inventario insuficiente (fase 13).** Coordinar con Inventario/Pedidos.
   La guía no define si Inventario debe bloquear Pagos: acordar el comportamiento
   para ese caso sin inventar eventos o condiciones dentro de Pagos.
6. **Caída y recuperación (fase 15).** Detener SOLO el consumidor de Pagos con
   Ctrl+C y crear un pedido. Ver el mensaje esperando en pagos_pedidos_queue.
   Reiniciar con `pagos.cmd consume`; verificar que llega PENDIENTE y no se
   duplica. Si el mensaje ya había sido recibido antes de detener el consumidor,
   estará guardado en MySQL, no esperando nuevamente en RabbitMQ.
7. **Envío pendiente (fase 15).** En un entorno de prueba, interrumpir la conexión
   de Pagos al broker y resolver un pedido ya recibido: debe conservar la decisión
   y mostrar envío pendiente. Restablecer conexión y ejecutar `recover` o el
   consumidor. No apagar ni alterar el broker compartido para provocar la falla.
8. **Panel de RabbitMQ (fase 16).** Mostrar Connections, Channels, Exchanges,
   Queues, Consumers y Messages. Explicar Pedidos -> exchange fanout -> cola de
   Pagos -> consumidor; después Pagos -> exchange topic -> colas de los grupos.
9. **Demostración final (fase 18).** Los nueve grupos ejecutan compra, inventario,
   pago, factura, notificación y reporte. Las dos comunicaciones sincrónicas
   (fase 10) y los tres flujos asincrónicos (fase 11) son requisitos del ecosistema.

Para observar resultados propios sin consumir la cola de otro grupo, opcionalmente:

```bat
pagos.cmd setup --demo-results
pagos.cmd observe
```

Estos comandos sí usan el exchange normal y una cola de observación separada;
prepararla antes de decidir y detener el observador con Ctrl+C. Ver un mensaje
allí no demuestra que Facturación, Pedidos u otro servicio lo haya procesado.

## 7. Alcance de la entrega local

La recepción real de pedidos se observó en consola. Las pruebas locales de
lógica, interfaz y decisiones están separadas de las pruebas de transporte.
La integración con los servicios reales y la demostración conjunta siguen
pendientes hasta tener sus evidencias; no se dan por cumplidas por usar colas simuladas.

No se cambia el modo manual ni los contratos del equipo sin acuerdo. No se
modifican servicios de otros grupos. No subir `.env`, `vendor/`, `composer.phar`,
credenciales ni datos de pruebas. No se realizaron commits ni push a GitHub.

## 8. Tres ventanas de CMD para la demostración

Con Apache y MySQL encendidos, abre tres ventanas. En cada una entra primero
con `cd /d C:\xampp\htdocs\grupoB_Pagos` y ejecuta el comando correspondiente:

| Ventana | Comando | Qué muestra |
| --- | --- | --- |
| 1. Microservicio | `pagos.cmd consume` | Recepción, registro de pedidos y recuperación |
| 2. JSON de entrada | `pagos.cmd observe-input` | Copia de los JSON nuevos publicados por Pedidos |
| 3. JSON de salida | `pagos.cmd observe-output` | Copia de PagoAprobado / PagoRechazado publicados por Pagos |

Espera a que ambos observadores indiquen que están activos antes de crear el
pedido o pulsar Pagar/Rechazar. Solo muestran eventos publicados desde ese
momento; no reproducen mensajes anteriores. Cada ventana usa una cola temporal
exclusiva, que desaparece al cerrarse su conexión. No quitan mensajes al
consumidor de Pagos ni a los demás grupos. Ctrl+C detiene cada ventana.

Ver la salida confirma la publicación y recepción de la copia en el observador;
no confirma que Facturación haya generado una factura. Además, la cola del
observador cuenta como destino para RabbitMQ: puede haber publicación confirmada
aunque todavía falte enlazar la cola de Facturación. Verifica el resultado en
ese microservicio durante la prueba conjunta.

## 9. Nombre del cliente por RPC

En el detalle del pago se consulta al servicio Clientes con el clienteId que ya
está guardado. El navegador solicita el nombre por separado para que la consulta
no retrase ni bloquee los botones de decisión. No requiere Python ni otra consola.

Se utiliza el mismo RABBITMQ_URL de Pagos, exchange predeterminado vacío, cola
`cliente.consultar` y cuerpo `{"id":25}`. La respuesta debe conservar correlation_id
y enviarse al reply_to temporal. Contrato: `{"status":200,"data":{"id":25,
"primer_nombre":"Ana","primer_apellido":"Ortiz"}}`; también se admite `nombre`.
Se unen primer/segundo nombre y primer/segundo apellido, y se verifica el ID.
Una respuesta 404 muestra «Cliente no encontrado»; fallos de conexión, timeout o
respuesta inválida muestran «Nombre no disponible». El ID sigue visible.

Opciones en .env: CLIENT_RPC_QUEUE (predeterminado cliente.consultar) y
CLIENT_RPC_TIMEOUT (4 segundos de espera de respuesta, máximo 10). La conexión
usa los tiempos AMQP ya configurados. La consulta ocurre una vez por carga del
pedido en la página, sin guardar datos personales adicionales en la base de Pagos
ni modificar el JSON de los eventos. Para volver a consultar, recarga la página.
El equipo de Clientes debe mantener su worker RPC encendido en el mismo broker/vhost.

## 10. Panel de pagos pendientes

La pantalla Pago muestra a la derecha los pedidos con estado PENDIENTE, con
ID de pedido, ID de cliente e importe. Pulsa un pedido para abrir sus detalles.
La lista se consulta cada cinco segundos, se pausa en pestañas ocultas y muestra
los 100 pendientes más antiguos y el total. Al decidir, el pedido sale de esta
lista aunque su evento aún esté pendiente de publicación; permanece en Historial.
En pantallas estrechas el panel se coloca debajo de la consulta o del detalle.

Los archivos JavaScript y CSS llevan una versión automática para evitar que
el navegador conserve una interfaz antigua. La consulta del nombre tiene un
límite de espera visible de 15 segundos y no bloquea los botones de decisión.
