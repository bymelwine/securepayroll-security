-- OBSOLÈTE (phase 6). Les données personnelles de démonstration ne sont plus insérées en clair par SQL :
-- elles sont désormais CHIFFRÉES par bin/seed-personal-data.php, à partir de database/seeds/personal_data_demo.json
-- (AES-256-GCM, clés hors base). Ce fichier n'est plus référencé par bin/init-local.py et ne fait rien.
SELECT 'Semence déplacée vers bin/seed-personal-data.php (phase 6).' AS note;
