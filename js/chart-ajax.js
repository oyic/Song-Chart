jQuery( document ).ready(function($) {
    
    $(".num-only").keydown(function (e) {
        // Allow: backspace, delete, tab, escape, enter and .
        if ($.inArray(e.keyCode, [46, 8, 9, 27, 13, 110, 190]) !== -1 ||
             // Allow: Ctrl+A, Command+A
            (e.keyCode === 65 && (e.ctrlKey === true || e.metaKey === true)) || 
             // Allow: home, end, left, right, down, up
            (e.keyCode >= 35 && e.keyCode <= 40)) {
                 // let it happen, don't do anything
                 return;
        }
        // Ensure that it is a number and stop the keypress
        if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
            e.preventDefault();
        }
    });
    $('.save-me').on('click',
    	function(event) {
    	/* Act on the event */
    	event.preventDefault();
    	$post = $(this).prev('input');
    	getAPIUrl = window.location.protocol + "//" + window.location.host + "/wp-json/boomlabs/v1/ajax";
    	// //alert("ID: "+$post.attr('id')+"\n Carta: "+$post.data('carta') +"\n Week: "+$post.data('week')+"\n Year: "+$post.data('year')+"\n URL: "+getAPIUrl
    	// +"\n Votes: "+ $post.val() );
    	
    	$.ajax({
    		url: getAPIUrl,
    		dataType:'JSON',
    		type:'POST',
            async: false,
            cache:false,
    		data:{
    			song:$post.attr('id'),
    			carta:$post.data('carta_id'),
    			period:$post.data('period_id'),
    			dj_vote:$post.val(),
    		}
    		
    	}).done(function(data){

    		
    	
    	}).error(function(error) {
    		alert("error:"+error.responseText);
    	});
    	

    	getAPIUrl = window.location.protocol + "//" + window.location.host + "/wp-json/boomlabs/v1/ajaxrank";
    	$.ajax({
    		url: getAPIUrl,
    		dataType:'JSON',
    		type:'POST',
            async: false,
            cache:false,
    		data:{
    			carta:$post.data('carta'),
    			week:$post.data('week'),
    			year:$post.data('year'),
    		}
    		
    	}).done(function(data){

    		location.reload();
        // alert(data);
    	
    	}).error(function(error) {
    		alert("error:"+error.responseText);
    	});
    	
    });
    
    $('.add-dj-votes').on('click', function(event) {
        event.preventDefault();
        /* Act on the event */
        forminput = $('#dj-votes-popup #dj_votes');
        $('#dj-votes-popup #title').html('Song: <strong>'+ $(this).data('title')+"<strong>" );
        $('#dj-votes-popup #period').html('Period: <strong>'+ $(this).data('nice-period')+"<strong>");
        $('#dj-votes-popup #chart').html('Chart: <strong>'+ $(this).data('nice-chart')+"<strong>" );

        forminput.val($(this).data('value'));
        form = $('#change-me');
        form.attr('data-id',$(this).data('id'));
        form.attr('data-song',$(this).data('song'));
        form.attr('data-chart',$(this).data('chart'));
        form.attr('data-period',$(this).data('period'));
        $('#dj-votes-popup').modal();

    });
    $('#change-me').on('click', function(event) {
        event.preventDefault();
        id = $(this).data('id');
        value = $('#dj_votes').val();
        song_id = $(this).data('song');
        chart_id = $(this).data('chart');
        period_id = $(this).data('period');

        getAPIUrl = window.location.protocol + "//" + window.location.host + "/wp-json/boomlabs/v2/djvotes";
        $.ajax({
            url: getAPIUrl,
            dataType:'JSON',
            type:'POST',
            async: false,
            cache:false,
            data:{
                id:id,
                value:value,
                song_id:song_id,
                chart_id:chart_id,
                period_id:period_id
            }
            
        }).done(function(data){

            location.reload();
        // alert(data);
        
        }).error(function(error) {
            alert("error:"+error.responseText);
        });
        
    });

    
});