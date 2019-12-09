function setLanguage(lang, path='/') {
	var myDate = new Date();
	myDate.setMonth(myDate.getMonth() + 12);
	document.cookie = "lang=" + lang + ";expires=" + myDate + "; path=" + path;
	location.reload();
}

function checkKeyboard(ob,e){
	re = /\d|\w|[\.\$@\*\\\/\+\-\^\!\(\)\[\]\~\%\&\=\?\>\<\{\}\"\'\,\:\;\_]/g;
	if (e.key.match(re) == null) return false;
	return true;
}

$("#inputPassword").keydown(function(e){
	if (!checkKeyboard($(this),e)){
		$(this).attr('data-original-title', $(this).data('i18n')).tooltip('show');
	} else {
		$(this).attr('data-original-title', '').tooltip('hide');
	}
})

function dump(obj) {
    var out = '';
    for (var i in obj) {
		out += i + ": " + obj[i] + "\n";
    }
    alert(out);
}

function resetForm(myFormElement) {
	var elements = myFormElement.elements;
	myFormElement.reset();
	
	for(i=0; i<elements.length; i++) {
		field_type = elements[i].type.toLowerCase();
		switch(field_type) {
			case "text":
			case "password":
			case "textarea":
			case "date":
				elements[i].value = "";
				break;
			case "radio":
			case "checkbox":
				if (elements[i].checked) {
					elements[i].checked = false;
				}
				break;
			case "select-one":
				elements[i].selectedIndex = 0;
				break;
			case "select-multi":
				elements[i].selectedIndex = -1;
				break;
			default:
				break;
		}
	}
}

function submitOnce(e, text) {
	var formElements = e.form.elements;
	for (var i = 0; i < formElements.length; i++) {
		if (!formElements[i].checkValidity()) return;
	};
	e.disabled = true;
	msg = e.dataset.message;
	if ((text) && (e.tagName == "BUTTON")) {
		e.innerText = msg;
	} else if ((text) && (e.tagName == "INPUT")) {
		e.value = msg;
	} else {
		icon = e.getElementsByTagName('i')[0];
		icon.className = 'fas fa-spinner fa-spin';
	}
	DBWait(e.dataset.wait);
	e.form.submit();
}

function quickEdit(event, form) {
	// Ctrl+Enter
	if((event.ctrlKey) && ((event.keyCode == 0xA)||(event.keyCode == 0xD))) {
		document.getElementById('quick-edit').value = 'true';
    }
}

var DBWait = function(message){
	message = ('string' === typeof message && message.length > 0) ? '<h1 class="css3">'+message+'</h1>' : '';
	var container = document.createElement('div');
	container.className = "css3 page-container";
	container.innerHTML = '<div class="css3 wrapper">\
						<div class="css3 part left"></div>\
						'+message+'\
						<div class="css3 round1 round2"></div>\
						<div class="css3 part right"></div>\
					</div>';
	document.body.appendChild(container);
}

function scrollToAnchor(id){
    var aTag = $("a[name='"+id+"']");
	if (aTag.length) $('html,body').animate({scrollTop: aTag.offset().top},'slow');
}

function alphaScroll(group) {
	var $s = $('#client-selector');
	if (!$s.find('[data-group="' + group + '"]').length) return;
	var optionTop = $s.find('[data-group="' + group + '"]').offset().top;
	var selectTop = $s.offset().top;
	$s.scrollTop($s.scrollTop() + (optionTop - selectTop));
}


$(document).ready(function() {
	var navbarOffset = $('#navbar').outerHeight();
	$('[data-toggle="tooltip"]').tooltip();
    var dataTable = $('.sortable').DataTable({
		"paging": false,
		"info": false,
		language: {
			url: document.documentElement.dataset.path+
				'locale/'+
				document.documentElement.dataset.locale+
				'/datatables.json'
		},
		"aoColumnDefs": [{
			"bSortable": false,
			"aTargets": ["sorting_disabled"]
		}],
		"order": [],
		"fixedHeader": {
			headerOffset: navbarOffset
		}
	});
	if ($(".list")[0]) {
		$timeout = 1200;
		if ($('.list').hasClass('noanimate')) return;
		if ($('.list').hasClass('fastanimation')) $timeout = 500;
		$('html, body').animate({scrollTop: $('.list').offset().top-(navbarOffset-2)}, $timeout);
	};
} );