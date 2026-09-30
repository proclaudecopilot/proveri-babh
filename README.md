# БАБХ Регистър v6 (proveri-babh)

WordPress плъгин: регистър на хранителните добавки от БАБХ — база данни, chunked ETL за Excel файловете на агенцията, diff между качвания (нови / обновени / заличени / възстановени), детекция на регулаторни флагове и категории. Работи паралелно с v5.6 (отделни таблици `babh6_*`).

## Нова версия
Вдигни версията на **двете места** в `babh-register-v6.php` (header `Version:` и `define('BABH6_VERSION', ...)`) и push-ни в `main`. GitHub Action-ът прави тага и Release-а сам. Сайтът вижда новата версия през LOGADOR GitHub Updater (Settings → GitHub ъпдейти) и я предлага в Plugins → Update now / auto-updates.
