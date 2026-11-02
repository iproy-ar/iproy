# Publicar iProy en GitHub

1. Creá en GitHub un repositorio público vacío llamado `iproy` (sin README ni licencia: ya están en esta carpeta).
2. En tu computadora, dentro de esta carpeta:

```
git init -b main
git add .
git commit -s -m "iProy 0.9.1: publicación inicial"
git tag -s v0.9.1 -m "iProy 0.9.1"          # o: git tag -a si no usás firma GPG
git remote add origin https://github.com/<tu-usuario>/iproy.git
git push -u origin main --tags
```

3. En GitHub → Settings:
   - **Pages:** Source = GitHub Actions (publica la guía de implementación).
   - **Branches:** proteger `main` (Pull Request obligatorio y verificación del workflow).
   - **About:** descripción, sitio `https://iproy.com.ar` y temas: `fhir`, `hl7`, `interoperabilidad`, `salud`, `mantenimiento`, `argentina`.
4. Archivá la versión en **Software Heritage**: https://archive.softwareheritage.org/save/ con la URL del repositorio.
5. Inscribí el software como obra publicada en la **Dirección Nacional del Derecho de Autor** (Trámites a Distancia).
