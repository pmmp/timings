/*
 * Aikar's Minecraft Timings Parser
 *
 * Written by Aikar <aikar@aikar.co>
 * http://aikar.co
 * http://starlis.com
 *
 * @license MIT
 */

function showMyChildren() {
    showChildren($(this));
}
function showChildren(element) {
    $(element).show()
    if ($(element).hasClass('hidden-children')) {
        console.log('showing children');
        $(element).click(hideMyChildren).addClass('visible-children').removeClass('hidden-children');
        var depth = $(element).data('depth');
        $(element).nextUntil(
            function() {
                return $(this).data('depth') == depth;
            }
        ).each(function() {
            if ($(this).data('depth') == depth + 1){
                $(this).show();
            }
        })
    }
}

function hideMyChildren() {
    hideChildren($(this));
}

function hideChildren(element) {
    if ($(element).hasClass('visible-children')) {
        console.log('hiding children');
        var depth = $(element).data('depth');
        $(element).click(showMyChildren).addClass('hidden-children').removeClass('visible-children');
        $(element).nextUntil(function() {
            return $(this).data('depth') <= depth;
        }).each(function() {
            $(this).hide();
            if ($(this).hasClass('visible-children')) {
                $(this).click(showMyChildren).addClass('hidden-children').removeClass('visible-children');
            }
        })
    } else {
        console.log('wtf?')
        console.log(element);
    }
}

function hideAll() {
    $(this).hide();
    hideChildren($(this));
}

function learnMore(ev) {
	ev.stopPropagation();
	$("#info-" + $(this).data('info')).dialog({width: "80%", modal: true});
}

$(document).ready(function() {
    $('#paste_toggle').click(function() {
        $('#paste').toggle();
    });
    $('.show_rest').click(function() {
        if($(this).data('shown')) {
            $(this).data('shown', false);
            var table = $(this).closest('.timings-table-border').find('.timings-table');
            table.find('.children-hidden-by-default').each(hideMyChildren);
            table.find('.hidden').each(hideAll);
        }else{
            $(this).data('shown', true);
            $(this).closest('.timings-table-border').find('.timings-table').find('.event').each(showMyChildren);
        }
    })
    $('.show_all').click(function() {
        if($(this).data('shown')) {
            $(this).data('shown', false);
            $('.children-hidden-by-default').each(hideMyChildren);
            $('.hidden').each(hideAll);
        }else{
            $(this).data('shown', true);
            $('.event').each(showMyChildren);
        }
    })
    $('.event.hidden-children').click(showMyChildren);
    $('.event.visible-children').click(hideMyChildren);
    $('.learnmore').click(learnMore);
});
