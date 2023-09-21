<?php return array(
    'root' => array(
        'name' => 'woocommerce/woocommerce-subscriptions',
        'pretty_version' => 'dev-release/5.5.0',
        'version' => 'dev-release/5.5.0',
        'reference' => '9c5944431141ef588b010663dd1539c41d12cf69',
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
            'pretty_version' => '6.2.0',
            'version' => '6.2.0.0',
            'reference' => '47cfe92d60239d1b8b12a5f640a3772b0e4e1272',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../woocommerce/subscriptions-core',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'woocommerce/woocommerce-subscriptions' => array(
            'pretty_version' => 'dev-release/5.5.0',
            'version' => 'dev-release/5.5.0',
            'reference' => '9c5944431141ef588b010663dd1539c41d12cf69',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
