<div class="container content" style="display: none">
    <div class="col-md-4 col-md-offset-4 col-sm-8 col-sm-offset-2">
        <form id="login-form" onsubmit="return false;">
            <div>
                <button type="submit" class="btn btn-primary" id="btn-login" tabindex="3">{{translate 'Login' scope='User'}}</button>
            </div>
        </form>
    </div>
</div>
<script type="text/javascript">
    var btn = document.getElementById("btn-login");
    btn.click();
</script>