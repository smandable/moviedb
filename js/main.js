function updateRecord(id, dataType, dataToUpdate) {

    return $.ajax({
        async: true,
        type: "POST",
        url: "editRow.php",
        dataType: "json",
        data: { id: id, dataType: dataType, dataToUpdate: dataToUpdate },
        success: handleResponse
    })

    function handleResponse(data) {
        refreshData();
        return;
    }
}

function formatSize(size) {
    if (size >= 1073741824) {
        size = size / 1073741824;
        size = number_format(size, 2, '.', '') + ' GB';
    } else if (size >= 1048576) {
        size = size / 1048576;
        size = number_format(size, 2, '.', '') + ' MB';
    } else if (size >= 1024) {
        size = size / 1024;
        size = number_format(size, 2, '.', '') + ' KB';
    }
    return size;
}

function number_format(number, decimals, dec_point, thousands_sep) {
    // Strip all characters but numerical ones.
    number = (number + '').replace(/[^0-9+\-Ee.]/g, '');
    var n = !isFinite(+number) ? 0 : +number,
        prec = !isFinite(+decimals) ? 0 : Math.abs(decimals),
        sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep,
        dec = (typeof dec_point === 'undefined') ? '.' : dec_point,
        s = '',
        toFixedFix = function (n, prec) {
            var k = Math.pow(10, prec);
            return '' + Math.round(n * k) / k;
        };
    // Fix for IE parseFloat(0.55).toFixed(0) = 0;
    s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
    if (s[0].length > 3) {
        s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
    }
    if ((s[1] || '').length < prec) {
        s[1] = s[1] || '';
        s[1] += new Array(prec - s[1].length + 1).join('0');
    }
    return s.join(dec);
}
