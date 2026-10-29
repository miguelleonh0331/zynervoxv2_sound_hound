-- Fase 1: conservar la estructura actual y cambiar el nombre de la tabla de leads.
-- Ejecutar después de detener/asegurar que no haya un dialer activo.
RENAME TABLE carsa_initial_survey_queue TO carsa_initial_survey;
