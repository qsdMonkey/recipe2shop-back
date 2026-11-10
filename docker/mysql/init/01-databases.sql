-- Exécuté une seule fois, à la création du volume MySQL.
-- La base principale (recipe2shop) est créée par MYSQL_DATABASE.
-- On ajoute la base de test utilisée par PHPUnit (suffixe _test ajouté par Doctrine en env "test").
CREATE DATABASE IF NOT EXISTS `recipe2shop_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `recipe2shop_test`.* TO 'app'@'%';
FLUSH PRIVILEGES;
