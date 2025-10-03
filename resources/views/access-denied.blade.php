<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - UW ADFS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .access-denied-container {
            max-width: 600px;
            margin: 50px auto;
            padding: 30px;
            text-align: center;
        }
        .access-denied-icon {
            font-size: 4rem;
            color: #dc3545;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="access-denied-container">
            <div class="access-denied-icon">
                🚫
            </div>
            
            <h1 class="text-danger mb-4">Access Denied</h1>
            
            @if(session('error'))
                <div class="alert alert-danger">
                    {{ session('error') }}
                </div>
            @endif
            
            <p class="text-muted mb-4">
                {{ config('uw-adfs.access_control.access_denied_message', 'You do not have permission to access this application.') }}
            </p>
            
            @if(session('access_control_details'))
                <div class="card mt-4">
                    <div class="card-header">
                        <h5>Access Control Details</h5>
                    </div>
                    <div class="card-body text-start">
                        @php $details = session('access_control_details'); @endphp
                        
                        <p><strong>Reason:</strong> {{ $details['reason'] }}</p>
                        
                        @if(isset($details['checks']))
                            <h6>Access Checks Performed:</h6>
                            <ul class="list-unstyled">
                                @foreach($details['checks'] as $checkType => $checkResult)
                                    <li class="mb-2">
                                        <span class="badge {{ $checkResult['passed'] ? 'bg-success' : 'bg-danger' }}">
                                            {{ $checkResult['passed'] ? '✓' : '✗' }}
                                        </span>
                                        <strong>{{ ucfirst(str_replace('_', ' ', $checkType)) }}:</strong>
                                        {{ $checkResult['reason'] }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif
            
            <div class="mt-4">
                <a href="{{ url('/') }}" class="btn btn-primary">Return to Home</a>
                <a href="{{ route('saml.logout') }}" class="btn btn-outline-secondary">Logout</a>
            </div>
            
            <div class="mt-4">
                <small class="text-muted">
                    If you believe this is an error, please contact your system administrator.
                </small>
            </div>
        </div>
    </div>
</body>
</html>