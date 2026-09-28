# Источник B2B-Center

Источник `b2b_center` читает только общедоступную серверную таблицу
`https://www.b2b-center.ru/market/`. Авторизация, cookies, электронная подпись
и платный API личного кабинета не используются. Из списка импортируются номер
процедуры, название, категория, организатор, даты публикации и окончания.

```dotenv
B2B_CENTER_ENABLED=true
B2B_CENTER_CATALOG_URL=https://www.b2b-center.ru/market/
B2B_CENTER_REQUEST_TIMEOUT_SECONDS=15
B2B_CENTER_POLL_INTERVAL_SECONDS=3600
B2B_CENTER_USER_AGENT="TenderFinder/1.0 (+public tender catalog monitoring)"
```

Разрешён только HTTPS URL официального домена с точным путём `/market/` и без
поисковых параметров. Источник выключен в шаблонах окружения: перед включением
в production нужно повторно проверить доступность страницы и актуальные
условия площадки. Изменение структуры таблицы считается ошибкой опроса и не
перезаписывает успешный снимок пустым результатом.
