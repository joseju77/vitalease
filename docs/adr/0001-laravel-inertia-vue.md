# ADR-0001: Usar Laravel, Inertia, Vue y arquitectura MVC simple

* Estado: Aceptada

## Contexto

VitalEase necesita una base web mantenible para desarrollar sus módulos iniciales sin introducir complejidad
innecesaria.

El equipo requiere una arquitectura que permita avanzar rápido, reutilizar conocimiento existente y mantener backend y
frontend coordinados dentro del mismo proyecto.

Laravel soporta distintos estilos arquitectónicos, desde MVC simple (lógica en controladores, modelos y form
requests) hasta enfoques más estratificados con clases dedicadas de servicio o caso de uso. El proyecto está en una
etapa temprana y su alcance actual (ingreso de pacientes, consultas, gestión de usuarios/roles/permisos) todavía no
involucra flujos de negocio transversales que justifiquen claramente una capa de abstracción adicional.

## Decisión

Se usará Laravel como backend, Inertia como puente entre backend y frontend, y Vue para la interfaz, con una
arquitectura MVC simple: la lógica de la aplicación reside en controladores, modelos Eloquent y form requests (para
validación y autorización), sin introducir una capa de servicio o caso de uso dedicada en esta etapa.

Esta decisión se toma porque el equipo domina estas tecnologías, porque permiten construir una aplicación web
integrada sin separar una API pública desde el inicio, y porque el alcance actual del proyecto no requiere una capa
adicional entre los controladores y los modelos.

## Alternativas consideradas

- Backend Laravel con API separada y frontend SPA independiente.
- Aplicación server-rendered sin Vue.
- Capa de servicio/caso de uso dedicada entre controladores y modelos.

## Consecuencias

### Positivas

- Se reduce la complejidad inicial frente a una API pública separada.
- Backend y frontend pueden evolucionar dentro del mismo monolito.
- El equipo puede avanzar más rápido al usar tecnologías conocidas y una arquitectura sin capas adicionales.
- Inertia permite construir interfaces ricas sin duplicar contratos HTTP como en una SPA separada.
- Es más rápido construir funcionalidades con menos código repetitivo en las primeras etapas del proyecto.

### Negativas

- La aplicación queda acoplada al flujo Laravel + Inertia.
- Si en el futuro se requiere una API pública, habrá que definir contratos explícitos adicionales.
- El frontend depende de convenciones compartidas con Laravel para props, rutas y validaciones.
- Los controladores pueden crecer a medida que se añaden funcionalidades.

### Compromisos aceptados

- Se prioriza velocidad de desarrollo y simplicidad inicial sobre independencia total entre frontend y backend.
- Se acepta mantener convenciones claras para evitar desorden entre controladores, páginas Inertia y componentes Vue.
- Si la complejidad de la lógica de negocio crece significativamente, se podrá introducir una capa de
  servicio/caso de uso de forma incremental, ya que Laravel no impone ninguno de los dos estilos.
