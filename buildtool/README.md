# BUILD TOOL

### Requerimientos

- Node.js v8.11.3+
- gulp-cli v2.2.0+

## Instalación

Para usar este script es necesario tener instalado node y luego ejecutar:

```shell
cd ./buildtool
npm install
```
## Construir el archivo de distribución del plugin

```shell
npm run build
```

## Sincronizar su direcorio de desarrollo con su copia del repositiorio 

Esta tarea sincronizará su directorio de desarrollo en su sitio Joomla con la copia del repositiorio.

1. Editar el archivo **gulpfile.js**  y reemplazar los valores de las variables PLUGIN_NAME y OS_MEMBERSHIP_PRO_PLUGINS_PATH por los valores adecuados:
    ```javascript
    const PLUGIN_NAME = 'dls_mercadopago';
    const OS_MEMBERSHIP_PRO_PLUGINS_PATH =`/var/www/<DIRECTORIO-SITIO-PARA-DESARROLLO>/components/com_osmembership/plugins`;
    ```

2. Ejecute:
    ```shell
    (cd buildtool; gulp syncFolders)
    ```
    Or
    ```shell
    (cd buildtool; npm run sync)
    ```
