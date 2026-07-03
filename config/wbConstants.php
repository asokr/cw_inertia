<?php
return array(
    'PRODUCTS_PER_PAGE' => 100,
    'PAGES_PER_CATALOG' => 100,
    'FEEDBACKS_PER_PAGE' => 20,
    'QUESTIONS_PER_PAGE' => 30,
    'URLS' =>
    array(
        'MAIN_MENU' => 'https://www.wildberries.ru/webapi/menu/main-menu-ru-ru.json',
        'PRODUCT' =>
        array(
            'CONTENT' => 'https://wbx-content-v2.wbstatic.net/ru/{}.json',
            'CARD' => 'https://basket-%s.wbbasket.ru/vol%s/part%s/%s/info/ru/card.json',
            'SELLERS' => 'https://basket-0{0}.wb.ru/vol{1}/part{2}/{3}/info/sellers.json',
            'EXTRADATA' => 'https://www.wildberries.ru/webapi/product/{}/data',
            'DETAILS' => 'https://card.wb.ru/cards/detail',
            'FEEDBACKS' => 'https://public-feedbacks.wildberries.ru/api/v1/feedbacks/site',
            'QUESTIONS' => 'https://questions.wildberries.ru/api/v1/questions',
            'DELIVERYDATA' => 'https://card.wb.ru/cards/list',
        ),
        'SEARCH' =>
        array(
            'SIMILAR_BY_NM' => 'https://www.wildberries.ru/webapi/recommendations/similar-by-nm/{}',
            'TOTALPRODUCTS' => 'https://search.wb.ru/exactmatch/ru/female/v3/search',
            'EXACTMATCH' => 'https://search.wb.ru/exactmatch/ru/female/v4/search',
            'CATALOG' => 'https://wbxcatalog-ru.wildberries.ru/{}/catalog',
            'ADS' => 'https://catalog-ads.wildberries.ru/api/v5/search',
            'CAROUSEL_ADS' => 'https://carousel-ads.wildberries.ru/api/v4/carousel',
            'HINT' => 'https://search.wb.ru/suggests/api/v3/hint',
        ),
        'IMAGES' =>
        array(
            // 'TINY' => 'http://img1.wbstatic.net/small/new/{0}0000/{1}.jpg?r={2}',
            'SMALL' => 'https://basket-%s.wbbasket.ru/vol%s/part%s/%s/images/c246x328/%s.webp',
            // 'MEDIUM' => 'https://images.wbstatic.net/c516x688/new/{0}0000/{1}-{3}.jpg?r={2}',
            'BIG' => 'https://basket-%s.wbbasket.ru/vol%s/part%s/%s/images/big/%s.webp',
            'FEEDBACK_BASE' => 'https://feedbackphotos.wbstatic.net/',
        ),
        'ADV' =>
        array(
            'AUTH' => 'https://cmp.wildberries.ru/passport/api/v2/auth/wild_v3_upgrade',
            'USER' => 'https://cmp.wildberries.ru/passport/api/v2/auth/introspect',
            // 'X_SUPPLIER_ID' => 'https://cmp.wildberries.ru/backend/supplierslist',
            'ADV_LIST' => 'https://cmp.wildberries.ru/backend/api/v3/atrevds',
            'ADV_PLACEMENT' => 'https://cmp.wildberries.ru/backend/api/v2/search/%s/placement',
            'ADV_BUDGET' => 'https://cmp.wildberries.ru/backend/api/v2/search/%s/budget',
            'ADV_WORDS_STAT' => 'https://cmp.wildberries.ru/backend/api/v2/search/%s/stat-words',
            'ADV_SET_EXCLUDED' => 'https://cmp.wildberries.ru/backend/api/v2/search/%s/set-excluded',
            'ADV_PHRASE_PLUS' => 'https://cmp.wildberries.ru/backend/api/v2/search/%s/set-plus',
            'ADV_SAVE' => 'https://cmp.wildberries.ru/backend/api/v2/search/%s/save',
            'ADV_AD_PAUSE' => 'https://cmp.wildberries.ru/backend/api/v2/search//%s/pause',
            'SALES' => 'https://suppliers-stats.wildberries.ru/api/v1/supplier/sales?dateFrom=2022-09-04&flag=1&key=MWMzOWZhNDgtNTNiMS00ZDQ3LWFmMjMtNTFkYWUxNTBlZDNh'
        ),
        'LK' =>
        array(
            'TRENDS' => 'https://trending-searches.wb.ru/api'
        )
    ),
    'APPTYPES' =>
    array(
        'DESKTOP' => 1,
        'ANDROID' => 32,
        'IOS' => 64,
    ),
    'WAREHOUSES' =>
    array(
        'KOLEDINO' =>
        array(
            'id' => 507,
            'title' => 'Коледино',
        ),
        'NOVOSIBIRSK' =>
        array(
            'id' => 686,
            'title' => 'Новосибирск',
        ),
        'HABAROVSK' =>
        array(
            'id' => 1193,
            'title' => 'Хабаровск',
        ),
        'EKATERINBURG' =>
        array(
            'id' => 1733,
            'title' => 'Екатеринбург',
        ),
        'SPB_UTK' =>
        array(
            'id' => 2737,
            'title' => 'Санкт-Петербург Уткина Заводь',
        ),
        'SC_VOLGOGRAD' =>
        array(
            'id' => 6144,
            'title' => 'СЦ Волгоград',
        ),
        'SC_YAROSLAVL' =>
        array(
            'id' => 6154,
            'title' => 'СЦ Ярославль',
        ),
        'SC_RIYAZAN' =>
        array(
            'id' => 6156,
            'title' => 'СЦ Рязань',
        ),
        'SC_KRASNOGORSK' =>
        array(
            'id' => 6159,
            'title' => 'СЦ Красногорск',
        ),
        'SC_YUJNYE_VOROTA' =>
        array(
            'id' => 158328,
            'title' => 'СЦ Южные Ворота',
        ),
        'SPB_SUSHARY' =>
        array(
            'id' => 159402,
            'title' => 'Санкт-Петербург Шушары',
        ),
        'KAZAHSTAN' =>
        array(
            'id' => 204939,
            'title' => 'Склад Казахстан',
        ),
        'KRASNODAR' =>
        array(
            'id' => 130744,
            'title' => 'Склад Краснодар',
        ),
        'CHEHOV' =>
        array(
            'id' => 206968,
            'title' => 'Чехов (Новоселки)',
        ),
        'ELEKTROSTAL' =>
        array(
            'id' => 120762,
            'title' => 'Электросталь',
        ),
        'ELEKTROSTAL_KBT' =>
        array(
            'id' => 121709,
            'title' => 'Электросталь КБТ',
        ),
        'ALEXIN' =>
        array(
            'id' => 206348,
            'title' => 'Алексин',
        ),
        'BELYE_STOLBY' =>
        array(
            'id' => 206236,
            'title' => 'Белые Столбы',
        ),
        'KAZAN' =>
        array(
            'id' => 117986,
            'title' => 'Казань',
        ),
        'KREKSHYNO_KBT' =>
        array(
            'id' => 124731,
            'title' => 'Крёкшино КБТ',
        ),
        'PODOLSK' =>
        array(
            'id' => 117501,
            'title' => 'Подольск',
        ),
        'SC_ASTRAHAN' =>
        array(
            'id' => 169872,
            'title' => 'СЦ Астрахань',
        ),
        'SC_BARNAUL' =>
        array(
            'id' => 172430,
            'title' => 'СЦ Барнаул',
        ),
        'SC_BELAYA_DACHA' =>
        array(
            'id' => 205228,
            'title' => 'СЦ Белая Дача',
        ),
        'SC_BRYANSK' =>
        array(
            'id' => 172940,
            'title' => 'СЦ Брянск',
        ),
        'SC_BLADIMIR' =>
        array(
            'id' => 144649,
            'title' => 'СЦ Владимир',
        ),
        'SC_IVANOVO' =>
        array(
            'id' => 203632,
            'title' => 'СЦ Иваново',
        ),
        'SC_IJEVSK' =>
        array(
            'id' => 158140,
            'title' => 'СЦ Ижевск',
        ),
        'SC_IRUTSK' =>
        array(
            'id' => 131643,
            'title' => 'СЦ Иркутск',
        ),
        'SC_KALUGA' =>
        array(
            'id' => 117442,
            'title' => 'СЦ Калуга',
        ),
        'SC_KIROV' =>
        array(
            'id' => 205205,
            'title' => 'СЦ Киров',
        ),
        'SC_KOMSOMILSKAYA' =>
        array(
            'id' => 154371,
            'title' => 'СЦ Комсомольская',
        ),
        'SC_KURSK' =>
        array(
            'id' => 140302,
            'title' => 'СЦ Курск',
        ),
        'SC_KURIANOVSKAYA' =>
        array(
            'id' => 156814,
            'title' => 'СЦ Курьяновская',
        ),
        'SC_LIPETSK' =>
        array(
            'id' => 160030,
            'title' => 'СЦ Липецк',
        ),
        'SC_LOBNYA' =>
        array(
            'id' => 117289,
            'title' => 'СЦ Лобня',
        ),
        'SC_MYTISCHI' =>
        array(
            'id' => 115650,
            'title' => 'СЦ Мытищи',
        ),
        'SC_NABEREJNYE_CHELNY' =>
        array(
            'id' => 204952,
            'title' => 'СЦ Набережные Челны',
        ),
        'SC_NIJNIY_NOVGOROD' =>
        array(
            'id' => 118535,
            'title' => 'СЦ Нижний Новгород',
        ),
        'SC_PERM' =>
        array(
            'id' => 147019,
            'title' => 'СЦ Пермь',
        ),
        'SC_PODREZKOVO' =>
        array(
            'id' => 124716,
            'title' => 'СЦ Подрезково',
        ),
        'SC_SARATOV' =>
        array(
            'id' => 158929,
            'title' => 'СЦ Саратов',
        ),
        'SC_SEROV' =>
        array(
            'id' => 169537,
            'title' => 'СЦ Серов',
        ),
        'SC_SIMFEROPOL' =>
        array(
            'id' => 144154,
            'title' => 'СЦ Симферополь',
        ),
        'SC_SMOLENSK' =>
        array(
            'id' => 117497,
            'title' => 'СЦ Смоленск',
        ),
        'SC_TAMBOV' =>
        array(
            'id' => 117866,
            'title' => 'СЦ Тамбов',
        ),
        'SC_TVER' =>
        array(
            'id' => 117456,
            'title' => 'СЦ Тверь',
        ),
        'SC_TIUMEN' =>
        array(
            'id' => 117819,
            'title' => 'СЦ Тюмень',
        ),
        'SC_UFA' =>
        array(
            'id' => 149445,
            'title' => 'СЦ Уфа',
        ),
        'SC_CHEBOKSARY' =>
        array(
            'id' => 203799,
            'title' => 'СЦ Чебоксары',
        ),
        'SC_CHELYABINSK' =>
        array(
            'id' => 132508,
            'title' => 'СЦ Челябинск',
        ),
    ),
    'DESTINATIONS' =>
    array(
        'KRASNODAR' =>
        array(
            'ids' =>
            array(
                0 => -1059500,
                1 => -108082,
                2 => -269701,
                3 => 12358048,
            ),
            'regions' =>
            array(
                0 => 64,
                1 => 58,
                2 => 83,
                3 => 4,
                4 => 38,
                5 => 80,
                6 => 33,
                7 => 70,
                8 => 82,
                9 => 86,
                10 => 30,
                11 => 69,
                12 => 22,
                13 => 66,
                14 => 31,
                15 => 40,
                16 => 1,
                17 => 48,
            ),
        ),
        'MOSCOW' =>
        array(
            'ids' =>
            array(
                0 => -1257786
            ),
            'regions' =>
            array(
                0 => 68,
                1 => 64,
                2 => 83,
                3 => 4,
                4 => 38,
                5 => 80,
                6 => 33,
                7 => 70,
                8 => 82,
                9 => 86,
                10 => 75,
                11 => 30,
                12 => 69,
                13 => 22,
                14 => 66,
                15 => 31,
                16 => 40,
                17 => 1,
                18 => 48,
                19 => 71,
            ),
        ),
        'KAZAHSTAN' =>
        array(
            'ids' =>
            array(
                0 => 85,
                1 => -3479876,
                2 => 12358412,
                3 => 12358388,
            ),
        ),
        'HABAROVSK' =>
        array(
            'ids' =>
            array(
                0 => -1221185,
                1 => -151223,
                2 => -1782064,
                3 => -1785054,
            ),
            'regions' =>
            array(
                0 => 64,
                1 => 4,
                2 => 38,
                3 => 80,
                4 => 70,
                5 => 82,
                6 => 86,
                7 => 30,
                8 => 69,
                9 => 22,
                10 => 66,
                11 => 40,
                12 => 1,
                13 => 48,
            ),
        ),
        'NOVOSIBIRSK' =>
        array(
            'ids' =>
            array(
                0 => -1221148,
                1 => -140294,
                2 => -1751445,
                3 => -364763,
            ),
            'regions' =>
            array(
                0 => 64,
                1 => 58,
                2 => 83,
                3 => 4,
                4 => 38,
                5 => 80,
                6 => 33,
                7 => 70,
                8 => 82,
                9 => 86,
                10 => 30,
                11 => 69,
                12 => 22,
                13 => 66,
                14 => 31,
                15 => 40,
                16 => 1,
                17 => 48,
            ),
        ),
        'EKATERINBURG' =>
        array(
            'ids' =>
            array(
                0 => -1113276,
                1 => -79379,
                2 => -1104258,
                3 => -5803327,
            ),
            'regions' =>
            array(
                0 => 64,
                1 => 58,
                2 => 83,
                3 => 4,
                4 => 38,
                5 => 80,
                6 => 33,
                7 => 70,
                8 => 82,
                9 => 86,
                10 => 30,
                11 => 69,
                12 => 22,
                13 => 66,
                14 => 31,
                15 => 40,
                16 => 1,
                17 => 48,
            ),
        ),
    ),
    'STORES' =>
    array(
        'UFO' =>
        array(
            0 => 117673,
            1 => 122258,
            2 => 122259,
            3 => 130744,
            4 => 117501,
            5 => 507,
            6 => 3158,
            7 => 124731,
            8 => 121709,
            9 => 120762,
            10 => 204939,
            11 => 117986,
            12 => 159402,
            13 => 2737,
            14 => 686,
            15 => 1733,
        ),
        'MSK' =>
        array(
            0 => 117673,
            1 => 122258,
            2 => 122259,
            3 => 125238,
            4 => 125239,
            5 => 125240,
            6 => 507,
            7 => 3158,
            8 => 117501,
            9 => 120602,
            10 => 120762,
            11 => 6158,
            12 => 121709,
            13 => 124731,
            14 => 130744,
            15 => 159402,
            16 => 2737,
            17 => 117986,
            18 => 1733,
            19 => 686,
            20 => 132043,
        ),
    ),
    'LOCALES' =>
    array(
        'RU' => 'ru',
    ),
    'CURRENCIES' =>
    array(
        'RUB' => 'rub',
    ),
    'SEX' =>
    array(
        'FEMALE' => 'female',
        'MALE' => 'male',
    ),
    'USERAGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/107.0.0.0 Safari/537.36',
);
