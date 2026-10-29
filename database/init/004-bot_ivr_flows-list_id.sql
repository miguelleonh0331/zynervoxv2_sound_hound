-- Asocia un flujo del IVR Builder a una lista de contactos
-- (zynervox_bot_lists/zynervox_bot_list, tablas del modulo bot_ivr de
-- campanas). La relacion se guarda SOLO de este lado (bot_ivr_flows);
-- no se toca zynervox_bot_lists.id_flujo, que es la relacion inversa en
-- construccion por el modulo bot_ivr (ver tmp/query-layer-stage en
-- mirmidon) -- evita el mismo tipo de colision que ya tuvimos con la
-- cuenta MySQL compartida.
--
-- Sin FK real a proposito: bot_ivr_flows y zynervox_bot_lists son de
-- modulos distintos: una lista puede borrarse sin que esto rompa el
-- guardado del flujo (el valor simplemente queda huerfano y el selector
-- de listas en el editor dejara de mostrarla).

ALTER TABLE bot_ivr_flows
  ADD COLUMN IF NOT EXISTS list_id BIGINT UNSIGNED NULL AFTER start_node_id;
