/* SpotEx Pay in the block checkout: the method, its title and description. Paying happens on SpotEx's page. */
(function () {
  var settings = window.wc.wcSettings.getSetting('spotex_pay_data', {});
  var el = window.wp.element.createElement;
  var decode = window.wp.htmlEntities.decodeEntities;
  var title = decode(settings.title || 'Crypto (SpotEx Pay)');
  var Content = function () {
    return el('p', null, decode(settings.description || ''));
  };
  window.wc.wcBlocksRegistry.registerPaymentMethod({
    name: 'spotex_pay',
    label: el('span', null, title),
    content: el(Content),
    edit: el(Content),
    canMakePayment: function () {
      return true;
    },
    ariaLabel: title,
    supports: { features: settings.supports || ['products'] },
  });
})();
