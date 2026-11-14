<?php

declare(strict_types=1);

/**
 * Datos iniciales: administrador, categorías, productos bilingües y ajustes.
 * Uso:
 *   php database/seed.php
 *   php database/seed.php --force-settings   (sobrescribe la configuración)
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;

$forceSettings = in_array('--force-settings', $argv ?? [], true);

echo "Sembrando datos de AppMenu…\n";

// ---------------------------------------------------------------- usuario admin
$email = (string) Config::get('seed.admin_email', 'admin@restaurante.local');
$password = (string) Config::get('seed.admin_password', 'Admin123!');
$admin = User::findByEmail($email);

if ($admin === null) {
    $id = User::createUser([
        'name' => 'Administrador',
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'admin',
        'is_active' => 1,
        'must_change_password' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo "✓ Administrador creado (#{$id}): {$email}\n";
} else {
    echo "· Administrador existente: {$email}\n";
}

// ------------------------------------------------------------------- categorías
$categories = [
    ['slug' => 'entradas', 'sort' => 1, 'emoji' => '🥗', 'es' => ['Entradas', 'Para abrir el apetito y compartir.'], 'en' => ['Starters', 'To open the appetite and share.']],
    ['slug' => 'platos-fuertes', 'sort' => 2, 'emoji' => '🍽️', 'es' => ['Platos fuertes', 'Nuestras recetas principales, cocinadas al momento.'], 'en' => ['Main courses', 'Our signature recipes, cooked to order.']],
    ['slug' => 'postres', 'sort' => 3, 'emoji' => '🍰', 'es' => ['Postres', 'El final dulce que merece tu mesa.'], 'en' => ['Desserts', 'The sweet ending your table deserves.']],
    ['slug' => 'bebidas', 'sort' => 4, 'emoji' => '🥂', 'es' => ['Bebidas', 'Vinos, cafés y refrescos de la casa.'], 'en' => ['Drinks', 'Wines, coffees and house refreshments.']],
];

$categoryIds = [];
foreach ($categories as $category) {
    $existing = Database::fetch('SELECT id FROM categories WHERE slug = :slug LIMIT 1', ['slug' => $category['slug']]);
    if ($existing !== null) {
        $categoryIds[$category['slug']] = (int) $existing['id'];
        echo "· Categoría existente: {$category['slug']}\n";
        continue;
    }

    $id = Category::createCategory([
        'slug' => $category['slug'],
        'sort_order' => $category['sort'],
        'is_active' => 1,
        'translations' => [
            'es' => ['name' => $category['es'][0], 'description' => $category['es'][1]],
            'en' => ['name' => $category['en'][0], 'description' => $category['en'][1]],
        ],
    ]);
    $categoryIds[$category['slug']] = $id;
    echo "✓ Categoría creada: {$category['slug']} (#{$id})\n";
}

// --------------------------------------------------------------------- productos
$products = [
    [
        'category' => 'entradas', 'slug' => 'bruschetta-de-tomate', 'price' => '6.50', 'emoji' => '🍅',
        'featured' => true, 'available' => true,
        'es' => ['Bruschetta de tomate', 'Pan artesanal tostado, tomate maduro, albahaca fresca y aceite de oliva virgen.', 'porción'],
        'en' => ['Tomato bruschetta', 'Toasted artisan bread, ripe tomato, fresh basil and extra virgin olive oil.', 'portion'],
    ],
    [
        'category' => 'entradas', 'slug' => 'croquetas-de-hongos', 'price' => '7.00', 'emoji' => '🍄',
        'featured' => false, 'available' => true,
        'es' => ['Croquetas de hongos', 'Cremosas por dentro, crujientes por fuera. Con alioli de ajo negro.', '6 unidades'],
        'en' => ['Mushroom croquettes', 'Creamy inside, crispy outside. Served with black garlic aioli.', '6 pieces'],
    ],
    [
        'category' => 'entradas', 'slug' => 'pulpo-a-la-brasa', 'price' => '12.00', 'emoji' => '🐙',
        'featured' => true, 'available' => true,
        'es' => ['Pulpo a la brasa', 'Pulpo tierno sobre puré de papa ahumado y pimentón de la vera.', 'porción'],
        'en' => ['Grilled octopus', 'Tender octopus over smoked potato purée and smoked paprika.', 'portion'],
    ],
    [
        'category' => 'entradas', 'slug' => 'ensalada-de-burrata', 'price' => '9.50', 'emoji' => '🧀',
        'featured' => false, 'available' => true,
        'es' => ['Ensalada de burrata', 'Burrata cremosa, tomates confitados, rúcula y vinagreta de miel.', 'porción'],
        'en' => ['Burrata salad', 'Creamy burrata, slow-roasted tomatoes, arugula and honey vinaigrette.', 'portion'],
    ],
    [
        'category' => 'platos-fuertes', 'slug' => 'risotto-de-azafran', 'price' => '16.00', 'emoji' => '🍚',
        'featured' => true, 'available' => true,
        'es' => ['Risotto de azafrán', 'Arroz carnaroli, azafrán, parmesano añejo y mantequilla de la casa.', 'plato'],
        'en' => ['Saffron risotto', 'Carnaroli rice, saffron, aged parmesan and house butter.', 'plate'],
    ],
    [
        'category' => 'platos-fuertes', 'slug' => 'salmon-glaseado', 'price' => '19.50', 'emoji' => '🐟',
        'featured' => true, 'available' => true,
        'es' => ['Salmón glaseado', 'Salmón al punto, glaseado de cítricos, vegetales de temporada.', 'plato'],
        'en' => ['Glazed salmon', 'Perfectly cooked salmon, citrus glaze and seasonal vegetables.', 'plate'],
    ],
    [
        'category' => 'platos-fuertes', 'slug' => 'lomo-de-res', 'price' => '24.00', 'emoji' => '🥩',
        'featured' => false, 'available' => true,
        'es' => ['Lomo de res', 'Corte madurado a la parrilla, papas rústicas y salsa de vino tinto.', 'plato'],
        'en' => ['Beef tenderloin', 'Grilled aged cut, rustic potatoes and red wine sauce.', 'plate'],
    ],
    [
        'category' => 'platos-fuertes', 'slug' => 'pollo-al-limon', 'price' => '15.00', 'emoji' => '🍗',
        'featured' => false, 'available' => true,
        'es' => ['Pollo al limón', 'Pechuga marinada, limón asado, cuscús de hierbas y yogur.', 'plato'],
        'en' => ['Lemon chicken', 'Marinated breast, roasted lemon, herb couscous and yogurt.', 'plate'],
    ],
    [
        'category' => 'platos-fuertes', 'slug' => 'pasta-a-la-trufa', 'price' => '17.50', 'emoji' => '🍝',
        'featured' => false, 'available' => true,
        'es' => ['Pasta a la trufa', 'Tagliatelle fresco, crema de trufa negra y parmesano.', 'plato'],
        'en' => ['Truffle pasta', 'Fresh tagliatelle, black truffle cream and parmesan.', 'plate'],
    ],
    [
        'category' => 'postres', 'slug' => 'tiramisu-de-la-casa', 'price' => '7.00', 'emoji' => '🍰',
        'featured' => true, 'available' => true,
        'es' => ['Tiramisú de la casa', 'Mascarpone, café espresso y cacao amargo. Receta de familia.', 'porción'],
        'en' => ['House tiramisu', 'Mascarpone, espresso and bitter cocoa. A family recipe.', 'portion'],
    ],
    [
        'category' => 'postres', 'slug' => 'tarta-de-chocolate', 'price' => '7.50', 'emoji' => '🍫',
        'featured' => false, 'available' => false,
        'es' => ['Tarta de chocolate', 'Chocolate 70 %, centro fundente y sal de mar.', 'porción'],
        'en' => ['Chocolate tart', '70% chocolate, molten center and sea salt.', 'portion'],
    ],
    [
        'category' => 'postres', 'slug' => 'panna-cotta', 'price' => '6.50', 'emoji' => '🍮',
        'featured' => false, 'available' => true,
        'es' => ['Panna cotta', 'Vainilla de Madagascar y compota de frutos rojos.', 'porción'],
        'en' => ['Panna cotta', 'Madagascar vanilla and red berry compote.', 'portion'],
    ],
    [
        'category' => 'bebidas', 'slug' => 'limonada-de-hierbabuena', 'price' => '4.00', 'emoji' => '🍋',
        'featured' => false, 'available' => true,
        'es' => ['Limonada de hierbabuena', 'Recién exprimida, con hierbabuena y un toque de jengibre.', 'vaso'],
        'en' => ['Mint lemonade', 'Freshly squeezed, with mint and a hint of ginger.', 'glass'],
    ],
    [
        'category' => 'bebidas', 'slug' => 'vino-tinto-de-la-casa', 'price' => '6.00', 'emoji' => '🍷',
        'featured' => false, 'available' => true,
        'es' => ['Vino tinto de la casa', 'Tempranillo joven, frutal y equilibrado. Copa de 150 ml.', 'copa'],
        'en' => ['House red wine', 'Young Tempranillo, fruity and balanced. 150 ml glass.', 'glass'],
    ],
    [
        'category' => 'bebidas', 'slug' => 'cafe-espresso', 'price' => '3.00', 'emoji' => '☕',
        'featured' => false, 'available' => true,
        'es' => ['Café espresso', 'Mezcla de la casa, tueste medio, aroma intenso.', 'taza'],
        'en' => ['Espresso', 'House blend, medium roast, intense aroma.', 'cup'],
    ],
    [
        'category' => 'bebidas', 'slug' => 'agua-con-gas', 'price' => '3.50', 'emoji' => '🫧',
        'featured' => false, 'available' => true,
        'es' => ['Agua con gas', 'Botella de 500 ml, bien fría.', 'botella'],
        'en' => ['Sparkling water', '500 ml bottle, served chilled.', 'bottle'],
    ],
];

$created = 0;
foreach ($products as $item) {
    $existing = Database::fetch('SELECT id FROM products WHERE slug = :slug LIMIT 1', ['slug' => $item['slug']]);
    if ($existing !== null) {
        continue;
    }

    $categoryId = $categoryIds[$item['category']] ?? null;
    if ($categoryId === null) {
        continue;
    }

    Product::createProduct([
        'category_id' => $categoryId,
        'slug' => $item['slug'],
        'price' => $item['price'],
        'is_available' => $item['available'],
        'is_featured' => $item['featured'],
        'is_active' => 1,
        'emoji' => $item['emoji'],
        'translations' => [
            'es' => ['name' => $item['es'][0], 'description' => $item['es'][1], 'unit_label' => $item['es'][2]],
            'en' => ['name' => $item['en'][0], 'description' => $item['en'][1], 'unit_label' => $item['en'][2]],
        ],
    ]);
    $created++;
}

echo $created > 0 ? "✓ Productos creados: {$created}\n" : "· Productos ya existentes\n";

// -------------------------------------------------------------------- ajustes
$existingSettings = count(Setting::allWithTranslations());

if ($existingSettings > 0 && !$forceSettings) {
    echo "· Configuración existente ({$existingSettings} claves). Usa --force-settings para sobrescribir.\n";
} else {
    $openingHours = [
        'monday' => ['open' => '12:00', 'close' => '22:00', 'closed' => false],
        'tuesday' => ['open' => '12:00', 'close' => '22:00', 'closed' => false],
        'wednesday' => ['open' => '12:00', 'close' => '22:00', 'closed' => false],
        'thursday' => ['open' => '12:00', 'close' => '22:00', 'closed' => false],
        'friday' => ['open' => '12:00', 'close' => '23:00', 'closed' => false],
        'saturday' => ['open' => '12:00', 'close' => '23:00', 'closed' => false],
        'sunday' => ['open' => '12:00', 'close' => '16:00', 'closed' => true],
    ];

    Setting::setMany([
        'phone' => '+58 412 555 0101',
        'whatsapp' => '584125550101',
        'email' => 'hola@casaoliva.local',
        'instagram' => '@casaoliva',
        'facebook' => 'casaoliva',
        'maps_url' => 'https://maps.google.com/?q=Casa+Oliva',
        'currency_code' => 'USD',
        'currency_symbol' => '$',
        'currency_locale' => 'en-US',
        'currency_decimals' => 2,
        'default_lang' => 'es',
        'timezone' => 'America/Caracas',
        'opening_hours' => $openingHours,
        'reservation_enabled' => true,
        'reservation_start' => '12:00',
        'reservation_end' => '22:00',
        'reservation_days' => [2, 3, 4, 5, 6, 7],
        'reservation_slot_minutes' => 30,
        'reservation_max_party' => 12,
        'reservation_capacity' => 20,
        'reservation_turn_minutes' => 90,
        'whatsapp_orders_enabled' => true,
        'order_hours_enabled' => true,
        'show_sold_out' => true,
        'public_menu_url' => '',
        'telegram_enabled' => false,
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'logo_path' => '',
        'hero_image_path' => '',
    ], [
        'business_name' => ['es' => 'Casa Oliva', 'en' => 'Casa Oliva'],
        'tagline' => ['es' => 'Cocina mediterránea de autor', 'en' => 'Signature Mediterranean cuisine'],
        'description' => [
            'es' => 'Un rincón cálido donde la tradición mediterránea se encuentra con ingredientes locales. Cocina honesta, vinos selectos y sobremesas que se alargan.',
            'en' => 'A warm corner where Mediterranean tradition meets local ingredients. Honest cooking, select wines and long after-dinner conversations.',
        ],
        'address' => ['es' => 'Calle 45 #12-34, Ciudad', 'en' => '45th Street #12-34, City'],
        'opening_hours_text' => [
            'es' => 'Martes a domingo · desde las 12:00',
            'en' => 'Tuesday to Sunday · from 12:00',
        ],
        'reservation_note' => [
            'es' => 'Confirmaremos tu reserva por teléfono o WhatsApp. Para grupos de más de 12 personas, escríbenos directamente.',
            'en' => 'We will confirm your reservation by phone or WhatsApp. For parties over 12, please contact us directly.',
        ],
    ]);

    echo "✓ Configuración inicial creada\n";
}

echo "Listo. Accede al panel con {$email} y cambia la contraseña temporal.\n";
