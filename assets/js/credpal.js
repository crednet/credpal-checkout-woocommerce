jQuery(function ($) {
  if (credpal_pay_data.order_status !== 'cancelled') {
    checkout();
  }

  function checkout() {
    const checkout = new Checkout({
      key: credpal_pay_data.merchant_id,
      product: credpal_pay_data.products,
      amount: credpal_pay_data.amount,
      ...credpal_pay_data.user,
      onClose: () => console.log('Widget closed'),
      onLoad: () => console.log('Widget loaded successfully'),
      onSuccess: (data) => {
        checkout.close();
        success(data);
      },
    });

    checkout.setup();

    return checkout.open();
  }

  function success(code) {
    console.log('Success dedededed');
    $.ajax({
      type: 'POST',
      dataType: 'json',
      url: credpal_pay_data.ajaxurl,
      data: {
        action: 'order_complete',
        order_id: credpal_pay_data.order_id,
      },
      success: function (response) {
        window.location.href = credpal_pay_data.redirect_url;
      },
    });
  }

  $('#credpal_form').click(function () {
    checkout();
  });
});
