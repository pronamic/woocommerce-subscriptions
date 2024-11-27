<?php return array(
    'root' => array(
        'name' => 'woocommerce/woocommerce-subscriptions',
        'pretty_version' => 'dev-release/6.9.1',
        'version' => 'dev-release/6.9.1',
        'reference' => 'c2bc23e1cc88e5c38bc2cc63365cca6a2a7dd741',
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
            'pretty_version' => '7.7.2',
            'version' => '7.7.2.0',
            'reference' => 'f4bce1c1368e0e547922df7580ad578b0c9beea2',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../woocommerce/subscriptions-core',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'woocommerce/woocommerce-subscriptions' => array(
            'pretty_version' => 'dev-release/6.9.1',
            'version' => 'dev-release/6.9.1',
            'reference' => 'c2bc23e1cc88e5c38bc2cc63365cca6a2a7dd741',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
