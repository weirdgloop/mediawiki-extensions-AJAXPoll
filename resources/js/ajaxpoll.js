var ajaxpollTmp;

var setupEventHandlers = function () {
	'use strict';
	$( '.ajaxpoll-answer-vote' ).on( 'mouseover', function () {
		var sp = $( this ).find( 'span' );
		ajaxpollTmp = sp.html();
		sp.text( sp.attr( 'title' ) );
		sp.attr( 'title', '' );
	} );

	$( '.ajaxpoll-answer-vote' ).on( 'mouseout', function () {
		var sp = $( this ).find( 'span' );
		sp.attr( 'title', sp.text() );
		sp.text( ajaxpollTmp );
	} );

	/* attach click handler */
	$( '.ajaxpoll-answer-name label' ).on( 'click', function ( event ) {
		var choice = $( this ).parent().parent(), poll, answer;
		event.preventDefault();
		event.stopPropagation();
		poll = choice.attr( 'poll' );

		$( '#ajaxpoll-ajax-' + poll ).css( 'display', 'inline-block' );
	} );
};
setupEventHandlers();
