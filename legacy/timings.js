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
        var border = $(this).closest('.timings-table-border');
        var rows = border.data('hidden-rows');

        if(border.data('expanded')) {
            border.data('expanded', false);
            border.find('.children-hidden-by-default').each(hideMyChildren);
            border.find('.hidden').each(hideAll);
            border.find('.show-rest-text').text('Show ' + rows + ' more rows');
            border.find('.expand-all-text').text('Expand all');
        }else{
            border.data('expanded', true);
            border.find('.event').each(showMyChildren);
            border.find('.show-rest-text').text('Hide ' + rows + ' rows');
            border.find('.expand-all-text').text('Collapse all');
        }
    })
    $('.show_all').click(function() {
        $('.timings-table').each(function() {
            $(this).data('expanded', true);
            $(this).find('.event').each(showMyChildren);
        })
    })
    $('.event.hidden-children').click(showMyChildren);
    $('.event.visible-children').click(hideMyChildren);
    $('.learnmore').click(learnMore);
});
