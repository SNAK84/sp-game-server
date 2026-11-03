-- Луна имеет собственный Склад альянса, лимит обороняющих флотов и дейтерий.
-- Существующие типы объектов сохраняются. Миграция повторно применима.
UPDATE vars
SET onPlanetType = CONCAT_WS(',', NULLIF(onPlanetType, ''), '3')
WHERE elementID = 34 AND NOT FIND_IN_SET('3', onPlanetType);
