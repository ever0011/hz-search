$(function () {

  // Initialize AutoComplete
  var ac = new AutoComplete($('#searchform'), $('#searchInput'), $('.suggestions'));
  ac.init();

  // Here comes the code for the actual search page
  $('.search-context-title').click(function (e) {
    var $block = $(this).closest('.search-context-block');
    if ($block.hasClass('open')) {
      $block.addClass('closed');
      $block.removeClass('open');
    } else {
      $block.addClass('open');
      $block.removeClass('closed');
    }
  });

});