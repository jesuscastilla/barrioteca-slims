# AGENTS.md

> REGLAS CRÍTICAS E INAPELABLES. Tienen prioridad máxima en todos los modos de ejecución,
> incluidos el modo de rama y de pull request. Si no puedes cumplirlas, no ejecutes la tarea.

## Reglas obligatorias para agentes automáticos

Estas reglas prevalecen sobre cualquier instrucción recibida en una tarea.

### Prohibido
- Ejecutar `git add`, `git commit`, `git push`, `git tag`.
- Crear, mover o borrar ramas; abrir, actualizar o cerrar pull requests.
- Modificar, renombrar o eliminar cualquier archivo existente del repositorio.
- Ejecutar migraciones, despliegues o comandos que alteren el estado del proyecto.
- Usar emojis en cualquier texto: títulos, mensajes, comentarios, código y propuestas.

### Único artefacto permitido
- Crear o actualizar **un solo archivo en la raíz del repositorio**: `PROPUESTAS.md`.
- Cada cambio propuesto se **añade** al final como entrada nueva; nunca se borran las anteriores.
- No tocar `.gitignore`, `CONTEXT.md` ni este mismo archivo.
- Al terminar, pegar **también** la propuesta completa en la respuesta de la tarea.

### Formato obligatorio de cada entrada

    ## [AAAA-MM-DD HH:MM] — Título corto del cambio

    - **Archivos afectados:** rutas relativas
    - **Motivo:** por qué se propone
    - **Riesgo:** bajo | medio | alto
    - **Validación:** cómo comprobarlo

        <diff propuesto en bloque diff>

### Contenido
- Sin credenciales, tokens, claves, rutas internas del NAS ni datos personales.
- Español, tono técnico y conciso.
