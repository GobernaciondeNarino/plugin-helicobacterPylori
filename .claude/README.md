# Herramientas de apoyo al desarrollo

Nada de lo que hay en esta carpeta se distribuye con el plugin ni se ejecuta
en el sitio: son herramientas del entorno de trabajo.

## Skills de diseño (`ui-ux-pro-max-skill`)

Colección de skills de interfaz y experiencia de usuario que se usó para las
decisiones de accesibilidad, contraste, jerarquía tipográfica y tamaño de los
objetivos táctiles del panel de administración y de los componentes públicos.

Pesa unos 9,4 MB (catálogos de tipografías e iconos), así que no se versiona
con el repositorio. Para instalarla:

```bash
git clone --depth 1 https://github.com/nextlevelbuilder/ui-ux-pro-max-skill /tmp/ui-ux
mkdir -p .claude/skills && cp -r /tmp/ui-ux/.claude/skills/* .claude/skills/
```

## Playwright

Las pruebas de navegador viven en `tests/` y se ejecutan con `npm test`.
Consulte la sección «Verificación» de `guia.md`.
