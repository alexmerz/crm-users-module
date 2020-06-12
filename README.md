# CRM Users Module

This documentation describes API handlers and CLI commands provided by this module for
others to use. It expects your application is based on the [CRM skeleton](https://github.com/remp2020/crm-skeleton)
provided by us.

## Installing module

We recommend using Composer for installation and update management.

```shell
composer require remp/crm-users-module
```

## Enabling module

Add installed extension to your `app/config/config.neon` file.

```neon
extensions:
	- Crm\UsersModule\DI\UsersModuleExtension
```

## AccessTokenAuthenticator

UsersModule generates an access token for every successful user authentication. This token can be used to authenticate the user in API calls.

You can log the user into the CRM automatically if you have such token thanks to the [`AccessTokenAuthenticator`](src/authenticator/AccessTokenAuthenticator.php).

### How to use

CRM checks if there's a cookie called `n_token` and extracts the value from it. If the value is valid access token (it's still present in the `access_tokens` table), and it doesn't belong to admin account, it logs user in automatically without requesting username or password.

This comes handy in case your login process is handled on other domain (e.g. in your CMS via CRM's API) and you want your users to get logged in only once.

## API documentation

All examples use `http://crm.press` as a base domain. Please change the host to the one you use
before executing the examples.

All examples use `XXX` as a default value for authorization token, please replace it with the
real tokens:

* *API tokens.* Standard API keys for server-server communication. It identifies the calling application as a whole.
They can be generated in CRM Admin (`/api/api-tokens-admin/`) and each API key has to be whitelisted to access
specific API endpoints. By default the API key has access to no endpoint. 
* *User tokens.* Generated for each user during the login process, token identify single user when communicating between
different parts of the system. The token can be read:
    * From `n_token` cookie if the user was logged in via CRM.
    * From the response of [`/api/v1/users/login` endpoint](https://github.com/remp2020/crm-users-module#post-apiv1userslogin) -
    you're free to store the response into your own cookie/local storage/session.

API responses can contain following HTTP codes:

| Value | Description |
| --- | --- |
| 200 OK | Successful response, default value | 
| 400 Bad Request | Invalid request (missing required parameters) | 
| 403 Forbidden | The authorization failed (provided token was not valid) | 
| 404 Not found | Referenced resource wasn't found | 

If possible, the response includes `application/json` encoded payload with message explaining
the error further.

---

#### GET `/api/v1/user/info`

API call returns basic user information based on provided user token.

##### *Headers:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| Authorization | Bearer *String* | yes | User token. |


##### *Example:*

```shell
curl -v –X GET http://crm.press/api/v1/user/info \ 
-H "Content-Type:application/json" \
-H "Authorization: Bearer XXX"
```

Response:

```json5
{
    "status": "ok",
    "user": {
        "id": 1,
        "email": "admin@example.com",
        "first_name": "Test",
        "last_name": "Admin"
    }
}
```

This was a response from default `UserAuthenticator`. If your application use some custom implementation of authenticator
(e.g. `FooAuthenticator`), the authenticator can add extra parameters to the response:

```json5
{
    "status": "ok",
    "user": {
        // ...
    },
    "foo": {
        "external_id": "baz",
        "custom_flag": true
    }
}
```

---

#### POST `/api/v1/users/login`

API call verifies provided credentials and returns user token.

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| Email | *String* | yes | User's email address. |
| Password | *String* | yes | User's plain text password. |
| Source | *String* | no | Source of the login indicating which implementation<br>of  *UserAuthenticator* to verify credentials.<br>The caller is responsible for providing source of the credentials<br>so the handler know which implementation can handle the verification.|


##### *Example:*

```shell
curl 'http://crm.press/api/v1/users/login/' \
  -H 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8' \
  -H 'Accept: application/json' \
  --data 'email=admin%40admin.sk&password=password'
```

Success response:

```json5
{
    "status": "ok",
    "user": {
        "id": 9,
        "email": "admin@admin.sk",
        "first_name": "Test",
        "last_name": "Admin",
        "roles": ["redaktor", "superadmin"] // admin roles
    },
    "access": {
        "token": "762eec3fe9f20d87cf865cb40cf6458b" // user token
    }
}
```

Invalid credentials response:

```json5
{
    "status": "error",
    "error": "auth_failed",
    "message": "Zadané heslo sa nezhoduje s našimi záznamami. Prihláste sa, prosím, tak, ako na webe Denníka N."
}
```

---

#### POST `/api/v1/users/email`

API calls checks whether provided email address is valid and available to use (for possible registration).

Additionally it checks whether the provided password is valid for given email address or not. It doesn't login the
user into the system and it also doesn't return any user token, it only verifies the password if it was provided.

##### *Params:* 

| Name | Value | Required | Description |
| --- |---| --- | --- |
| Email | *String* | yes | Email to verify |
| Password | *String* | no | Password to verify |


##### *Example:*

```shell
curl -v –X GET http://crm.press/api/v1/users/email \ 
  -H 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8' \
  -H 'Accept: application/json' \
  --data 'email=admin%40admin.sk'
```

Response when email is already taken:

```json5
{
    "email": "admin@admin.sk", // String; requested email
    "status": "taken", // String; allowed values ["available", "taken"]
    "id": 9, // Integer; ID of user if email is taken
    "password": null // Boolean; set only if password was provided in request
}
```

Response when email is available:

```json5
{
    "email": "admin@admin.cz",
    "status": "available",
    "id": null,
    "password": null
}
```

---

#### GET `/api/v1/users/create`

API for registration of user into the system. Password is generated automatically by the system and sent to user by email.

When the user is registered, he/she is automatically logged in and user token is also returned.

##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| email | *String* | yes | - |
| first_name | *String* | no | - |
| last_name | *String* | no | - |
| ext_id | *Integer* | no | User identificator from external system |
| source | *String* | no | Source of user registration - string label groupping users registered via the same source. |
| referer | *String* | no | Referer URL to indicate where user got registered. |
| note | *String* | no | Note visible only to administrators |
| send_email | *String* | no | Flag whether to send welcome email after the registration. If not provided, the system will send an email. |
| disable_email_validation | *Boolean* | no | Flag whether to bypass email address validation. If not provided, the system will validate email address. |


##### *Example:*

```shell
curl -v –X GET http://crm.press/api/v1/users/create \ 
  -H 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8' \
  -H 'Accept: application/json' \
  --data 'email=user%40user.sk'
```

Success response:

```json5
{
    "status": "ok",
    "user": {
        "id": 101,
        "email": "user@user.sk",
        "first_name": null,
        "last_name": null,
        "roles": [] // admin roles
    },
    "access": {
        "token": "762eec3fe9f20d87cf865cb40cf6458c" // user token
    }
}
```

---

#### GET `/api/v1/users/add-to-group`

Adds user to the provided group. Group serves for artificial user groupping based on your arbitrary criteria.

You can list your available groups in CRM admin at `/users/groups-admin/`.

##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token. |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| email | *String* | yes | Email to add to user group. |
| group_id | *Integer* | yes | ID of user group to be used. |


##### *Example:*

```shell
curl -X POST http://crm.press/api/v1/users/add-to-group \
  -H 'Authorization: Bearer XXX' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'email=user%40user.sk&group_id=1'
```

Response:

```json5
{
    "status": "ok"
}
```

---

#### GET `/api/v1/users/remove-from-group`

Removes the user from selected group. Group serves for artificial user groupping based on your arbitrary criteria.

You can list your available groups in CRM admin at `/users/groups-admin/`.

##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token. |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| email | *String* | yes | Email to remove from group. |
| group_id | *Integer* | yes | ID of user group to be removed from. |


##### *Example:*

```shell
curl -X POST http://crm.press/api/v1/users/remove-from-group \
  -H 'Authorization: Bearer XXX' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'email=user%40user.sk&group_id=1'
```

Response:

```json5
{
    "status": "ok"
}
```

---

#### GET `/api/v1/users/remove-from-group`

Removes the user from selected group. Group serves for artificial user groupping based on your arbitrary criteria.

You can list your available groups in CRM admin at `/users/groups-admin/`.

##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token. |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| email | *String* | yes | Email to remove from group. |
| group_id | *Integer* | yes | ID of user group to be removed from. |


##### *Example:*

```shell
curl -X POST http://crm.press/api/v1/users/remove-from-group \
  -H 'Authorization: Bearer XXX' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'email=user%40user.sk&group_id=1'
```

Response:

```json5
{
    "status": "ok"
}
```

---

#### GET `/api/v1/users/addresses`

Lists all user addresses. User is identified by email address.

##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token. |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| email | *String* | yes | Email to remove from group. |
| type | *String* | no | Type of address - types of addresses are managed by modules (e.g. `InvoiceModule` adds support for `invoice` address type. |


##### *Example:*

```shell
curl -X GET \
  'http://crm.press/api/v1/users/addresses?email=user@user.sk' \
  -H 'Authorization: Bearer XXX' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d email=user%40user.sk
```

Response:

```json5
{
    "status": "ok",
    "addresses": [
        {
            "user_id": 10, // Integer; ID of user
            "type": "print", // String; type of address
            "created_at": "2019-03-08T11:37:45+01:00", // RFC3339 date; address creation date
            "email": "user@user.sk", // String; email address of user
            "company_name": "", // String; company name
            "phone_number": "0800123456", // String; phone number (not validated)
            "company_id": "", // String: company ID
            "tax_id": "", // String: company tax ID
            "vat_id": "", // String: company vat ID
            "first_name": "Test", // String: first name of address (can be different from user's first name)
            "last_name": "User", // String; last name of address (can be different from user's first name)
            "address": "10th street", // String: street name
            "number": "368", // String; street number
            "zip": "81105", // String: zip code
            "city": "Bratislava", // String; city
            "country": "Slovensko" // String: user-friendly country name (internally represented by reference)
        }
    ]
}
```

---

#### POST `/api/v1/users/address`

Creates new address for given user.

Current implementation doesn't allow specification of country and the default application country is being
implicitely used.

##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token. |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| email | *String* | yes | Email to remove from group. |
| type | *String* | yes | Type of address - types of addresses are managed by modules (e.g. `InvoiceModule` adds support for `invoice` address type. |
| first_name | *String* | no | First name. |
| last_name | *String* | no | Last name. |
| address | *String* | no | Street name. |
| number | *String* | no | Street number. |
| zip | *String* | no | ZIP code. |
| city | *String* | no | City. |
| company_name | *String* | no | Name of the company (if it's corporate address) |
| company_id | *String* | no | ID of company (if it's corporate address) |
| tax_id | *String* | no | Tax ID of company (if it's corporate address) |
| vat_id | *String* | no | VAT ID of company (if it's corporate address) |
| phone_number | *String* | no | Phone number. |


##### *Example:*

```shell
curl -X POST \
  http://crm.press/api/v1/users/addresses \
  -H 'Authorization: Bearer XXX' \
  -H 'Cache-Control: no-cache' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'email=user%40user.sk&type=invoice&first_name=AdrName&last_name=AdrLastName&address=11th%20str.&number=112&zip=81105&city=Bratislava'
```

Response:

```json5
{
    "status": "ok",
    "address": {
        "id": 26929 // Integer; address ID
    }
}
```

---

#### POST `/api/v1/users/change-address-request`

Creates new address change request for given type of address and user. Change request might still need to be approved.
You should check if the address with given type exists before calling this - if not, create the address first
via [`users/address`](#post-apiv1usersaddress) API call.


##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token. |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| email | *String* | yes | Email to remove from group. |
| type | *String* | yes | Type of address - types of addresses are managed by modules (e.g. `InvoiceModule` adds support for `invoice` address type. |
| first_name | *String* | no | First name. |
| company_name | *String* | no | Name of the company (if it's corporate address) |
| last_name | *String* | no | Last name. |
| address | *String* | no | Street name. |
| number | *String* | no | Street number. |
| zip | *String* | no | ZIP code. |
| city | *String* | no | City. |
| country_id | *String* | no | Internal CRM of country. Default country is used if not provided. |
| phone_number | *String* | no | Phone number. |
| company_id | *String* | no | ID of company (if it's corporate address) |
| company_tax_id | *String* | no | Tax ID of company (if it's corporate address) |
| company_vat_id | *String* | no | VAT ID of company (if it's corporate address) |


##### *Example:*

```shell
curl -X POST \
  http://crm.press/api/v1/users/change-address-request \
  -H 'Authorization: Bearer XXX' \
  -H 'Cache-Control: no-cache' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'email=user%40user.sk&type=print&first_name=AdrName&last_name=AdrLastName&address=11th%20str.&number=112&zip=81105&city=Bratislava'
```

Response:

```json5
{
    "status": "ok",
    "address": {
        "id": 26929 // Integer; address ID
    }
}
```

If the address with given type doesn't exist yet, HTTP 400 is returned with following message:

```json5
{
    "status": "errror",
    "message": "Parent address not found"
}
```

---

#### POST `/api/v1/users/list`

Lists information of requested users (identified by user IDs). Endpoint requires pagination parameter to be included
and paginates the result by 1000 users in one response.

##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token. |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| user_ids | *String* | yes | JSON-encoded array of requested user IDs. |
| page | *Integer* | yes | Page number to retrieve (starting with 1). |


##### *Example:*

```shell
curl -X POST \
  http://crm.press/api/v1/users/list \
  -H 'Authorization: Bearer XXX' \
  -H 'Cache-Control: no-cache' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'user_ids=%5B9%2C10%5D&page=1'
```

Response:

```json5
{
    "status": "ok",
    "page": 1, // Integer; requested page number
    "totalPages": 1, // Integer; total page count
    "totalCount": 2, // Integer; total record count
    "users": { // Object; map of users keyed by userId with value object containing user data
        "9": {
            "id": 9, // Integer; ID of user
            "email": "admin@admin.sk" // String; email of user
        },
        "10": {
            "id": 10,
            "email": "user@user.sk"
        }
    }
}
```

---

#### POST `/api/v1/users/confirm`

Lists information of requested users (identified by user IDs). Endpoint requires pagination parameter to be included
and paginates the result by 1000 users in one response.

##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token. |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| email | *String* | yes | Email of user to confirm. Unconfirmed users might be deleted in the future or won't get the emails. |

##### *Example:*

```shell
curl -X POST \
  http://crm.press/api/v1/users/confirm \
  -H 'Authorization: Bearer XXX' \
  -H 'Cache-Control: no-cache' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d email=admin%40admin.sk
```

Response:

```json5
{
    "status": "ok"
}
```

---
### USER META INFORMATION API

The concept of meta user's information is to provide the way how to store user related data without need of the database structure changes.

Meta information for user is stored as a pair **key** - **value** and respect two rules:
* One value for one key
* More unique keys for one user     

Public property (**is_public**) defines the availability of meta information for the visual components of CRM administration and the availability for other modules by data providers.

#### POST `/api/v1/user-meta/upsert`

Create or update the meta information for given user.


##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token. |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| user_id | *Integer* | yes | ID of user to add/update meta information. |
| key | *String* | yes | Meta information key to set. |
| value | *String* | yes | Meta information value to set. |
| is_public | *Boolean* | no | Meta information property public to set. |

##### *Example:*

```shell
curl -X POST \
  http://crm.press/api/v1/user-meta/upsert \
  -H 'Authorization: Bearer XXX' \
  -H 'Cache-Control: no-cache' \
  -H 'Content-Type: application/json' \
  -d '{
    "user_id": 12345,
    "key": "foo",
    "value": "bar",
    "is_public": false
  }'
```

Response:

```json5
{
    "key": "foo",
    "value": "bar",
    "is_public": false
}
```

---

#### POST `/api/v1/user-meta/list`

Return all the meta information of specified user. You can specify meta information by using the meta information key.

##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token. |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| user_id | *Integer* | yes | The ID of user. |
| key | *String* | no | The meta information key of user to filter by. |

##### *Example:*

```shell
curl -X POST \
  http://crm.press/api/v1/user-meta/list \
  -H 'Authorization: Bearer XXX' \
  -H 'Cache-Control: no-cache' \
  -H 'Content-Type: application/json' \
  -d '{
    "user_id": 12345
  }'
```

Response:

```json5
[
    {
        "key" : "foo",
        "value" : "bar",
        "is_public" : false
    },
    {
        "key" : "fooz",
        "value" : "1",
        "is_public" : true
    }
]
```

---

#### POST `/api/v1/user-meta/key-users`

Return all users with specified meta information key and value.

##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token. |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| key | *String* | yes | The meta key information of user to filter by. |
| value | *String* | no | The meta value information of user to filter by. |

##### *Example:*

```shell
curl -X POST \
  http://crm.press/api/v1/user-meta/key-users \
  -H 'Authorization: Bearer XXX' \
  -H 'Cache-Control: no-cache' \
  -H 'Content-Type: application/json' \
  -d '{
    "key": "foo"
  }'
```

Response:

```json5
[
    {
        "user_id" : 1,
        "value" : "bar"
    },
    {
        "user_id" : 2,
        "value" : "friend"
    }
]
```

---

#### POST `/api/v1/user-meta/delete`

Delete the meta information of user by key. You can delete the meta information of user also only with specific value.

##### *Headers:*

| Name | Value | Required | Description |
| --- | --- | --- | --- |
| Authorization | Bearer *String* | yes | API token. |

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| user_id | *Integer* | yes | The ID of user. |
| key | *String* | yes | The meta information key to delete by. |
| value | *String* | no | The meta information value to delete by. |

##### *Example:*

```shell
curl -X POST \
  http://crm.press/api/v1/user-meta/delete \
  -H 'Authorization: Bearer XXX' \
  -H 'Cache-Control: no-cache' \
  -H 'Content-Type: application/json' \
  -d '{
    "user_id": 12345,
    "key": "gdpr"
  }'
```

---

#### POST `/api/v1/users/autologin-token-login`

API call verifies provided autologin token and returns user identity and token.

##### *Params:*

| Name | Value | Required | Description |
| --- |---| --- | --- |
| autologin_token | *String* | yes | User's autologin token. |


##### *Example:*

```shell
curl --location --request GET 'http://crm.press/api/v1/users/autologin-token-login?autologin_token=f8fb5c8d41e454852c0049cfe1031ac1'
```

Success response:

```json5
{
    "status": "ok",
    "user": {
        "id": 9,
        "email": "user@user.sk",
        "public_name": "user@user.sk",
        "first_name": "Test",
        "last_name": "User"
    },
    "access": {
        "token": "762eec3fe9f20d87cf865cb40cf6458b" // user token
    }
}
```

Invalid token response:

```json5
{
    "status": "error",
    "message": "Invalid token"
}
```

## Components

**AddressWidget**

Admin user detail address widget.

![alt text](docs/user_address.png "AddressWidget")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/AddressWidget/AddressWidget.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/UsersModule.php#L251)

**AutologinTokens**

Admin user detail tokens widget.

![alt text](docs/autologin_tokens.png "AutologinTokens")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/AutologinTokens/AutologinTokens.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/UsersModule.php#L204)

**MonthToDateUsersStatWidget**

Admin dashboard single stat widget.

![alt text](docs/relative_new_users.png "MonthToDateUsersStatWidget")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/MonthToDateUsersStatWidget/MonthToDateUsersStatWidget.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/UsersModule.php#L240)

**MonthUsersSmallBarGraphWidget**

Admin users header widget.

![alt text](docs/small_users_graph.png "MonthUsersSmallBarGraphWidget")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/MonthUsersSmallBarGraphWidget/MonthUsersSmallBarGraphWidget.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/UsersModule.php#L245)

**MonthUsersStatWidget**

Admin dashboard single stat widget.

![alt text](docs/month_new_users.png "MonthUsersStatWidget")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/MonthUsersStatWidget/MonthUsersStatWidget.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/UsersModule.php#L235)

**ActiveRegisteredUsersStatWidget**

Admin dashboard single stat widget.

![alt text](docs/active_registered_users.png "ActiveRegisteredUsersStatWidget")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/ActiveRegisteredUsersStatWidget/ActiveRegisteredUsersStatWidget.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/20ffe0c6035784412ec732565998caede418a0d1/src/UsersModule.php#L225)

**TodayUsersStatWidget**

Admin dashboard single stat widget.

![alt text](docs/today_new_users.png "TodayUsersStatWidget")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/TodayUsersStatWidget/TodayUsersStatWidget.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/UsersModule.php#L230)

**UserActionsLog**

Admin user detail listing component.

![alt text](docs/user_actions_log.png "UserActionsLog")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/UserActionsLog/UserActionLogAdmin.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/presenters/UserActionsLogAdminPresenter.php#L49)

**UserLoginAttempts**

Admin user detail listing component.

![alt text](docs/user_login_attempts.png "UserLoginAttempts")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/UserLoginAttempts/UserLoginAttempts.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/UsersModule.php#L194)

**UserMeta**

Admin user detail listing component.

![alt text](docs/user_meta.png "UserMeta")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/UserMeta/UserMeta.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/UsersModule.php#L209)

**UserPasswordChanges**

Admin user detail listing component.

![alt text](docs/password_changes.png "UserPasswordChanges")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/UserPasswordChanges/UserPasswordChanges.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/UsersModule.php#L199)

**UserSourceAccesses**

Admin user detail listing component.

![alt text](docs/last_accesses.png "UserSourceAccesses")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/UserSourceAccesses/UserSourceAccesses.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/UsersModule.php#L220)

**UserTokens**

Admin user detail listing component.

![alt text](docs/user_tokens.png "UserTokens")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/UserTokens/UserTokens.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/UsersModule.php#L214)

**DetailWidget**

Admin user detail meta widget.

![alt text](docs/detail_widget.png "DetailWidget")

[Source code](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/components/Widgets/DetailWidget/DetailWidget.php#L1)

[How to use](https://github.com/remp2020/crm-users-module/blob/0683e04c95027043d1d30f1d0aa868d9270e7aaf/src/presenters/UsersAdminPresenter.php#L381)
