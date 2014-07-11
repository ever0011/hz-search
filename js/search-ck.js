/**
 * This class controls the autocomplete input
 *
 * @param form : dom reference to the search form
 * @param input : dom reference to the search input
 * @param suggestions : dom reference to the suggestions ul
 *
 */

// Constructor function
var AutoComplete = function (form, input, suggestions) {

  this.init = function () {

    var _this = this;

    // Save dom references
    this.form = form;
    this.input = input;
    this.suggestions = suggestions;

    // Attach event listeners
    this.input.focus(this.openUp.bind(_this));
    this.input.blur(this.closeDown.bind(_this));
    this.suggestions.hover(function () {}, this.selectNormalSearch.bind(_this));
    this.input.keydown(this.keydownListener.bind(_this));
    this.form.submit(this.submitSearch.bind(_this));

    // On keyup in the search input, we give suggestions
    // in the case of NOT arrow up, arrow down or submit
    this.input[0].addEventListener('keyup', function (e) {
      if (e.which !== _this.ARROW_UP_KEY &&
          e.which !== _this.ARROW_DOWN_KEY &&
          e.which !== _this.ENTER_KEY
      ) {
        e.preventDefault();
        _this.giveSearchSuggestions(_this);
      }
    });

    // By default, the current suggestion is -1: normal search
    // if the values is >=0, the number is the index of the selected
    // suggestion
    this.currentSuggestion = -1;
  };

};
AutoComplete.prototype = {
  // Settings

  // Minimum string length for the autocomplete to start functioning
  MIN_LENGTH_FOR_SUGGESTIONS: 2,
  // Key codes
  ARROW_UP_KEY: 38,
  ARROW_DOWN_KEY: 40,
  ENTER_KEY: 13,
  // Elastic
  ELASTIC_SERVER: window.settings.get('elastic.server'),
  ELASTIC_INDEX: window.settings.get('elastic.index'),
  // Index URL
  INDEX_URL: window.settings.get('index.url'),


  // Submit search
  submitSearch: function (e) {
    e.preventDefault();
    document.location.href= this.INDEX_URL + '/Zoeken?q='+this.input.val()+'&fulltext=Search';
  },

  // Open suggestion
  openSuggestion: function (url) {
    document.location.href = url;
  },

  // Open up (animated)
  openUp: function () {
    this.suggestions.addClass('focus');
    this.input.addClass('selected');
    var text = this.input.val();
    if (text.length >= this.MIN_LENGTH_FOR_SUGGESTIONS) {
      this.suggestions.addClass('visible');
    }
  },

  // Close down (animated)
  closeDown: function () {
    this.suggestions.removeClass('visible');
    this.suggestions.removeClass('focus');
    this.input.removeClass('selected');
  },

  // Select a suggestion and update classes of selections list
  // -1 = normal search
  selectSuggestion: function (i) {
    this.currentSuggestion = i;
    this.suggestions.find('li').removeClass('selected');
    if (i >= 0) {
      $(this.suggestions.find('li')[i]).addClass('selected');
      this.input.removeClass('selected');
    } else {
      this.input.addClass('selected');
    }
  },

  // Select normal search instead of an autocomplete option
  selectNormalSearch: function () {
    this.selectSuggestion(-1);
  },

  // This loads the suggestions and updates the list
  giveSearchSuggestions: function (_this) {

    // Load the current search text
    var text = _this.input.val();

    if (text.length >= _this.MIN_LENGTH_FOR_SUGGESTIONS) {

      // Set the suggestions to visible
      _this.suggestions.addClass('visible');

      // Post to ElasticSearch
      $.post(
        _this.ELASTIC_SERVER+'/'+_this.ELASTIC_INDEX+'/_suggest',
        '{"suggestions":{"text":"'+text+'","completion":{"field":"suggest","fuzzy":{"fuzziness":0}}}}',
        function (e) {
          // Store the results in 'results'
          var result = e.suggestions[0].options;
          // Empty the suggestions UL and set the current selection to -1 (normal search)
          _this.suggestions.html("");
          // for each result, render a LI
          $(result).each(function (key,value) {
            // select a VN page
            var link_page = value.payload.url;
            if (value.payload.vn_pages.length > 0) {
              link_page = value.payload.vn_pages[0];
            }
            var element = $("<li data-key="+key+" data-url=\"" + link_page + "\"><h4>" + value.text + "</h4><p>"+value.payload.context+"</p></li>");
            element.mousedown(function (e) {
              e.preventDefault();
              document.location.href = link_page;
            });
            element.hover(function () {
              _this.selectSuggestion(key);
            });
            _this.suggestions.append(element);
          });
          // Select normal search as the selected option.
          _this.selectNormalSearch();
        }
      );
    } else {
      // less than 3 caracters: hide suggestions
      _this.suggestions.removeClass('visible');
    }
  },

  keydownListener: function (e) {
    var code = e.which, next;
    if (code === this.ARROW_UP_KEY) {
      // up
      e.preventDefault();
      next = this.currentSuggestion - 1;
      if (this.currentSuggestion === -1) {
        next = this.suggestions.find('li').length - 1;
      }
      this.selectSuggestion(next);
    } else if (code === this.ARROW_DOWN_KEY) {
      // down
      e.preventDefault();
      next = this.currentSuggestion + 1;
      if (this.currentSuggestion === this.suggestions.find('li').length-1) {
        next = -1;
      }
      this.selectSuggestion(next);
    } else if (code === this.ENTER_KEY) {
      // enter
      e.preventDefault();
      if (this.currentSuggestion === -1) {
        this.submitSearch(e);
      } else {
        this.openSuggestion($(this.suggestions.find('li')[this.currentSuggestion]).attr('data-url'));
      }
    }
  }
};



/* **********************************************
     Begin search.js
********************************************** */

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