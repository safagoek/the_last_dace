// Formlarda otomatik doğrulama için
(function() {
  'use strict';
  
  var forms = document.querySelectorAll('.needs-validation');
  
  Array.prototype.slice.call(forms).forEach(function(form) {
    form.addEventListener('submit', function(event) {
      if (!form.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
      }
      
      form.classList.add('was-validated');
    }, false);
  });
})();

// Dosya yükleme alanı için dosya adı gösterme
document.addEventListener('DOMContentLoaded', function() {
  var fileInputs = document.querySelectorAll('input[type="file"]');
  
  fileInputs.forEach(function(input) {
    input.addEventListener('change', function(e) {
      var fileName = e.target.files[0].name;
      var nextSibling = e.target.nextElementSibling;
      
      if (nextSibling && nextSibling.classList.contains('custom-file-label')) {
        nextSibling.innerHTML = fileName;
      }
    });
  });
});