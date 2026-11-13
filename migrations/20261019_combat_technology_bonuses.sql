-- Боевые технологии используют общий Factors. Значения — доли за уровень, не проценты.
-- Прибавка применяется в бою как 1 + Factors, без отдельного хардкода 0.1 * уровень.
-- Повторное применение безопасно. Уровни игроков и остальные бонусы не меняются.
UPDATE vars SET bonusAttack = 0.1, bonusAttackUnit = 0 WHERE elementID = 109;
UPDATE vars SET bonusDefensive = 0.1, bonusDefensiveUnit = 0 WHERE elementID = 110;
UPDATE vars SET bonusShield = 0.1, bonusShieldUnit = 0 WHERE elementID = 111;
