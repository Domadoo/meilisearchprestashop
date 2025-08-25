function ajaxProductClick(product, index) {
    let params = new URLSearchParams(document.location.search);
    if( !params.has('page') ) {
        params.set('page', 1);
    }
    let position = 1 + index + 48*(parseInt(params.get('page'), 10) - 1);
    const xhttp = new XMLHttpRequest();
    $url = base_url + "?token=1&action=productClick&id_product=" + product.dataset.idProduct + "&position=" + position;
    xhttp.onload = function() {
        if (this.status >= 200 && this.status < 300) {
            // Handle successful response
            console.log('Product click recorded successfully');
        } else {
            // Handle error response
            console.error('Error recording product click');
        }
    };
    xhttp.open("GET", $url, true);
    xhttp.getResponseHeader("Content-type", "text/html; charset=utf-8");
    xhttp.send();

}

let listProducts = document.getElementById('products');
listProducts.addEventListener('click', function(event) {

    if (event.target && event.target.closest('.thumbnail-container')) {

        let product = event.target.closest('.product').firstElementChild;
        let productsRow = document.querySelector('#js-product-list');
        ajaxProductClick(product, Array.from(productsRow.firstElementChild.children).indexOf(product.parentElement));

        let productHref = product.firstElementChild.firstElementChild.firstElementChild;
        let url = new URL(productHref.href);
        url.searchParams.set('id_session', id_statssearch);
        productHref.href = url.toString();

    }
});
