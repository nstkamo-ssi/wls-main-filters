WLS Filters — Основные разделы
Version 1.3.6

Изменения 1.3.6:
- Добавлен публичный REST API всех меток:
  GET /wp-json/wls-filters/v1/filters
  GET /wp-json/wls-filters/v1/labels
- Добавлен REST API меток конкретной публикации по ID:
  GET /wp-json/wls-filters/v1/post/{ID}
  GET /wp-json/wls-filters/v1/post/{ID}/labels
- Добавлен батч-endpoint до 100 публикаций за один запрос:
  GET /wp-json/wls-filters/v1/posts?ids=123,456,789
- Параметр ?enabled=1 ограничивает ответ только включёнными метками. Без параметра возвращаются все определения/назначения.
- В стандартные WordPress REST-ответы поддерживаемых post type автоматически добавляется поле wls_filters.
- Для любых других пользовательских endpoint'ов доступны PHP-функции:
  wls_main_filters_get_all_labels($only_enabled = false)
  wls_main_filters_get_post_labels($post_id, $only_enabled = false)
  wls_main_filters_get_posts_labels($post_ids, $only_enabled = false)
- Батч-функция получает taxonomy-термины одним wp_get_object_terms() для массива ID и ограничена 100 публикациями, чтобы не создавать N+1 нагрузку.
- REST endpoint публикации не раскрывает закрытые/непубличные записи без права read_post.
- Удалённые из настроек, но всё ещё назначенные публикации термины не теряются: API возвращает их как неактивные метки.

Изменения 1.3.5 сохранены без изменений.