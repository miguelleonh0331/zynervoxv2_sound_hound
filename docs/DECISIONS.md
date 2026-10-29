# Decisiones tecnicas

## 2026-10-29 - Base de codigo publicada de soundhound

La web publicada es la referencia para recuperar los cambios ausentes en la
fuente. Se compara contenido mediante SHA-256 y se conservan los bytes, sin
reformatear el codigo recuperado. Se mantiene `master`, que ya existia sin
commits, y se crean un commit base y otro de resincronizacion.

La fuente sera el lugar de edicion para futuros cambios; el despliegue sigue
siendo una operacion explicita. No se instala un sincronizador bidireccional
porque podria sobrescribir cambios o importar datos del servidor en Git.

La configuracion del servidor, secretos, bases de datos y servicios externos no
son codigo web. Se excluyen de esta importacion y se documentan sus dependencias.
