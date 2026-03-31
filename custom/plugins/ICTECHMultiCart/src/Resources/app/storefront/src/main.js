const PluginManager = window.PluginManager;

PluginManager.register(
    'ICTECHMultiCartAddToCart',
    () => import('./plugin/multi-cart-add-to-cart.plugin'),
    '[data-ictech-multi-cart-add-to-cart="true"]'
);
