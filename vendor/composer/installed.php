<?php return array(
    'root' => array(
        'name' => 'woocommerce/woocommerce-subscriptions',
        'pretty_version' => 'dev-release/6.0.0',
        'version' => 'dev-release/6.0.0',
        'reference' => 'b739a4cf35dc85bdd84a335c27295258c480fc4e',
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => false,
    ),
    'versions' => array(
        'composer/installers' => array(
            'pretty_version' => 'v1.12.0',
            'version' => '1.12.0.0',
            'reference' => 'd20a64ed3c94748397ff5973488761b22f6d3f19',
            'type' => 'composer-plugin',
            'install_path' => __DIR__ . '/./installers',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'roundcube/plugin-installer' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'shama/baton' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'woocommerce/subscriptions-core' => array(
            'pretty_version' => '6.8.0',
            'version' => '6.8.0.0',
            'reference' => '19d4f2acf246c7f8275438c9356eb79a141bdf4d',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../woocommerce/subscriptions-core',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'woocommerce/woocommerce-subscriptions' => array(
            'pretty_version' => 'dev-release/6.0.0',
            'version' => 'dev-release/6.0.0',
            'reference' => 'b739a4cf35dc85bdd84a335c27295258c480fc4e',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
