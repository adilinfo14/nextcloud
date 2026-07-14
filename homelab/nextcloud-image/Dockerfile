FROM nextcloud:latest

# tesseract-ocr* + ghostscript : OCR des PDF scannes (recherche plein texte).
# poppler-utils : requis par l'app LibreSign (pdfsig/pdfinfo, validation de signature).
# locales : genere en_US.UTF-8 (LibreSign/Java exigent une locale UTF-8, sinon
# echec silencieux sur les caracteres accentues dans les documents signes).
RUN apt-get update &&     apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-fra tesseract-ocr-eng ghostscript poppler-utils locales &&     rm -rf /var/lib/apt/lists/*
RUN sed -i "s/# en_US.UTF-8 UTF-8/en_US.UTF-8 UTF-8/" /etc/locale.gen && locale-gen
