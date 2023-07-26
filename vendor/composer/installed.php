<?php return array(
    'root' => array(
        'name' => 'woocommerce/woocommerce-subscriptions',
        'pretty_version' => 'dev-release/5.3.1',
        'version' => 'dev-release/5.3.1',
        'reference' => 'de1d1429946caab29ede2bf2d5bc00b16180c916',
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
            'pretty_version' => '6.1.0',
            'version' => '6.1.0.0',
            'reference' => '507720f600363a6c1e7da1b4b82660c7ab6a0c0e',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../woocommerce/subscriptions-core',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'woocommerce/woocommerce-subscriptions' => array(
            'pretty_version' => 'dev-release/5.3.1',
            'version' => 'dev-release/5.3.1',
            'reference' => 'de1d1429946caab29ede2bf2d5bc00b16180c916',
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
