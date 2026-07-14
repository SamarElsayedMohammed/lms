import http from 'http';

const options = {
  hostname: '127.0.0.1',
  port: 8000,
  path: '/api/subscription-plans',
  method: 'GET',
  headers: {
    'cf-ipcountry': 'EG'
  }
};

const req = http.request(options, (res) => {
  let data = '';
  res.on('data', (chunk) => {
    data += chunk;
  });
  res.on('end', () => {
    console.log('Status Code:', res.statusCode);
    console.log('Response Body:', data);
  });
});

req.on('error', (e) => {
  console.error(`Problem with request: ${e.message}`);
});

req.end();
