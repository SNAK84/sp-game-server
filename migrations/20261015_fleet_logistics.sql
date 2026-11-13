-- Применить один раз перед перезапуском сервера. Уровни существующих игроков не меняются.
-- Колонку techs.fleet_logistics_tech создаёт штатный Techs::init() из Vars.
-- ID 134 проверен в текущем коде, vars и vars_requirements; ID 108 остаётся прежним.
INSERT INTO vars
    (elementID, name, class, type, onPlanetType, onePerPlanet, factor, maxLevel, cost901, cost902, cost903)
VALUES
    (134, 'fleet_logistics_tech', 100, 2, '1,3', 0, 2, 255, 400, 800, 400);

INSERT INTO vars_requirements (elementID, requireID, requireLevel)
VALUES (134, 31, 1), (134, 108, 1);
